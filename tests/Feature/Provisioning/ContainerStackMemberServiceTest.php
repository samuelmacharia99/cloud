<?php

namespace Tests\Feature\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ComposeProjectStateProbe;
use App\Services\Provisioning\ContainerDeploymentEventRecorder;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\ContainerStackMemberService;
use App\Services\Provisioning\StackMemberActionException;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\Provisioning\StackMemberStateService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Restarting one container must be exactly one compose command scoped to it,
 * followed by a bounded wait, and must refuse anything that is not a database
 * or cache before touching the node.
 */
class ContainerStackMemberServiceTest extends TestCase
{
    use RefreshDatabase;

    private const YAML = <<<'YAML'
services:
  user-1-service-9-laravel:
    image: talksasa/laravel-runtime:8.3
    container_name: user-1-service-9-laravel
  db:
    image: mysql:8
    container_name: user-1-service-9-laravel-db
YAML;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $deployments = Mockery::mock(ContainerDeploymentService::class);
        $deployments->shouldReceive('ensureComposeFileExists')->byDefault();
        $this->instance(ContainerDeploymentService::class, $deployments);
    }

    #[Test]
    public function the_database_is_restarted_with_a_scoped_compose_command_and_polled_until_running(): void
    {
        [$service] = $this->deployed();
        $commands = [];
        $ssh = $this->ssh($commands);

        $inspects = [
            ['missing' => false, 'running' => true, 'state' => 'running', 'restarting' => false],   // before
            ['missing' => false, 'running' => false, 'state' => 'exited', 'restarting' => false],   // poll 1
            ['missing' => false, 'running' => true, 'state' => 'running', 'restarting' => false],   // poll 2
        ];
        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->andReturnUsing(function () use (&$inspects) {
            return array_shift($inspects) ?? ['missing' => false, 'running' => true, 'state' => 'running', 'restarting' => false];
        });

        $events = [];
        $recorder = Mockery::mock(ContainerDeploymentEventRecorder::class);
        $recorder->shouldReceive('record')->andReturnUsing(function ($s, $d, string $event, array $payload) use (&$events) {
            $events[$event] = $payload;
        });

        $sleeps = 0;
        $result = $this->service($inspector, $recorder, $ssh, function () use (&$sleeps) {
            $sleeps++;
        })->restart($service, 'db', $service->user);

        $this->assertTrue($result->running);
        $this->assertSame('db', $result->member->composeKey);
        $this->assertSame(ContainerStackMemberService::POLL_INTERVAL_SECONDS, $result->waitedSeconds);
        $this->assertSame(1, $sleeps);

        $restart = collect($commands)->first(fn (string $c) => str_contains($c, 'docker compose'));
        $this->assertSame("cd '/opt/talksasa/containers/user-1-service-9-laravel' && docker compose -f docker-compose.yml restart -t 30 'db'", $restart);
        $this->assertArrayHasKey(ContainerStackMemberService::EVENT_STARTED, $events);
        $this->assertArrayHasKey(ContainerStackMemberService::EVENT_RESTARTED, $events);
        $this->assertSame('user-1-service-9-laravel-db', $events[ContainerStackMemberService::EVENT_RESTARTED]['container_name']);
        $this->assertSame('running', $service->fresh()->containerDeployment->member_states['containers']['user-1-service-9-laravel-db']['state']);
    }

    #[Test]
    public function a_missing_container_is_created_with_up_instead_of_restart(): void
    {
        [$service] = $this->deployed();
        $commands = [];
        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn(['missing' => true, 'running' => false, 'state' => 'unknown', 'restarting' => false], ['missing' => false, 'running' => true, 'state' => 'running', 'restarting' => false]);
        $recorder = Mockery::mock(ContainerDeploymentEventRecorder::class)->shouldIgnoreMissing();

        $this->service($inspector, $recorder, $this->ssh($commands))->restart($service, 'db');

        $this->assertStringContainsString("up -d --no-deps --pull never 'db'", implode("\n", $commands));
    }

    #[Test]
    public function the_application_container_is_refused_before_any_ssh(): void
    {
        [$service] = $this->deployed();
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('exec');
        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldNotReceive('inspect');

        try {
            $this->service($inspector, Mockery::mock(ContainerDeploymentEventRecorder::class), $ssh)->restart($service, 'user-1-service-9-laravel');
            $this->fail('expected refusal');
        } catch (StackMemberActionException $e) {
            $this->assertSame('The App container is part of the application. Use Restart on the stack to restart it.', $e->userMessage());
        }

        try {
            $this->service($inspector, Mockery::mock(ContainerDeploymentEventRecorder::class), $ssh)->restart($service, 'nope');
            $this->fail('expected refusal');
        } catch (StackMemberActionException $e) {
            $this->assertStringContainsString('no container named "nope"', $e->userMessage());
        }
    }

    #[Test]
    public function a_second_click_while_the_first_runs_is_told_to_wait(): void
    {
        [$service] = $this->deployed();
        Cache::lock('stack-member-action:'.$service->containerDeployment->id, 60)->get();

        $this->expectException(StackMemberActionException::class);
        $this->expectExceptionMessage('Another action is still running on this stack.');

        $this->service(Mockery::mock(ContainerRuntimeInspector::class), Mockery::mock(ContainerDeploymentEventRecorder::class), Mockery::mock(SSHService::class))
            ->restart($service, 'db');
    }

    #[Test]
    public function a_container_that_never_comes_back_fails_after_the_deadline_with_an_event(): void
    {
        [$service] = $this->deployed();
        $commands = [];
        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn(['missing' => false, 'running' => false, 'state' => 'exited', 'restarting' => false, 'exit_code' => 1]);

        $events = [];
        $recorder = Mockery::mock(ContainerDeploymentEventRecorder::class);
        $recorder->shouldReceive('record')->andReturnUsing(function ($s, $d, string $event, array $payload) use (&$events) {
            $events[$event] = $payload;
        });

        try {
            $this->service($inspector, $recorder, $this->ssh($commands), fn () => null)->restart($service, 'db');
            $this->fail('expected the deadline to fail the action');
        } catch (StackMemberActionException $e) {
            $this->assertStringContainsString('not running after '.ContainerStackMemberService::READY_WAIT_SECONDS.'s', $e->userMessage());
        }

        $this->assertArrayHasKey(ContainerStackMemberService::EVENT_FAILED, $events);
        $this->assertSame(ContainerStackMemberService::READY_WAIT_SECONDS, $events[ContainerStackMemberService::EVENT_FAILED]['waited_seconds']);
        $this->assertNull(Cache::lock('stack-member-action:'.$service->containerDeployment->id, 1)->get() ? null : 'held', 'the lock is released');
    }

    private function service(ContainerRuntimeInspector $inspector, ContainerDeploymentEventRecorder $recorder, SSHService $ssh, ?\Closure $sleeper = null): ContainerStackMemberService
    {
        return new ContainerStackMemberService(
            new StackMemberResolver,
            $inspector,
            new StackMemberStateService(new ComposeProjectStateProbe, new StackMemberResolver),
            $recorder,
            fn (Node $node) => $ssh,
            $sleeper ?? fn () => null,
        );
    }

    /**
     * @param  list<string>  $commands
     */
    private function ssh(array &$commands): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands): string {
            $commands[] = $command;

            // The post-action refresh lists the stack; answer like the node would.
            if (str_contains($command, 'docker ps -a')) {
                return "user-1-service-9-laravel|user-1-service-9-laravel|user-1-service-9-laravel|running|Up|\n"
                    .'user-1-service-9-laravel|db|user-1-service-9-laravel-db|running|Up 3 seconds|';
            }

            return '';
        });
        $ssh->shouldReceive('disconnect');

        return $ssh;
    }

    /**
     * @return array{0: Service}
     */
    private function deployed(): array
    {
        $node = Node::factory()->containerHost()->create(['ssh_username' => 'root', 'ssh_password' => 'secret']);
        $service = Service::factory()->create(['status' => 'active', 'provisioning_driver_key' => 'container', 'node_id' => $node->id]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-1-service-9-laravel',
            'docker_compose_content' => self::YAML,
        ]);

        return [$service->fresh(['containerDeployment.node', 'user'])];
    }
}
