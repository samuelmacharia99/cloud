<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Listening" is a lower bar than "ready": the process answers, whatever it
 * answers. That is when a migration can run inside it. The gateway's own
 * 502/503/504 mean the backend is not there yet and do not count.
 */
class ApplicationListeningWaitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function any_answer_from_the_application_counts_but_the_gateway_answering_for_it_does_not(): void
    {
        $deployment = $this->deployment();
        $commands = [];
        $ssh = $this->ssh($commands, fn (string $command): bool => str_contains($command, 'curl'));

        $this->deployer()->waitForApplicationListening($ssh, $deployment, 'api/health', 30);

        $probe = implode("\n", $commands);
        $this->assertStringContainsString("http://127.0.0.1:{$deployment->assigned_port}/api/health", $probe);
        $this->assertStringContainsString('502|503|504|000|"") exit 1', $probe);
        $this->assertStringContainsString('[1-5][0-9][0-9]) exit 0', $probe);
    }

    #[Test]
    public function a_process_that_never_answers_fails_with_the_container_state_in_the_message(): void
    {
        $deployment = $this->deployment();
        $commands = [];
        $ssh = $this->ssh($commands, fn (string $command): bool => ! str_contains($command, 'curl'), 'status=running exit=0 command=["uvicorn"]');

        try {
            $this->deployer()->waitForApplicationListening($ssh, $deployment, '/', 1);
            $this->fail('Expected the wait to give up.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not start listening on 127.0.0.1:'.$deployment->assigned_port.'/', $e->getMessage());
            $this->assertStringContainsString('status=running', $e->getMessage());
        }
    }

    private function deployer(): ContainerDeploymentService
    {
        $deployer = Mockery::mock(ContainerDeploymentService::class)->makePartial();
        $deployer->shouldReceive('waitForContainerRunning')->andReturnNull();

        return $deployer;
    }

    /**
     * @param  list<string>  $commands
     * @param  callable(string): bool  $succeeds
     */
    private function ssh(array &$commands, callable $succeeds, string $diagnostic = ''): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use (&$commands, $succeeds, $diagnostic): string {
            $commands[] = $command;
            if (str_contains($command, 'docker inspect')) {
                return $diagnostic;
            }
            if (! $succeeds($command)) {
                throw new \RuntimeException('Command exited with status 1');
            }

            return '';
        });

        return $ssh;
    }

    private function deployment(): ContainerDeployment
    {
        return ContainerDeployment::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'node_id' => Node::factory()->containerHost()->create()->id,
            'assigned_port' => 30004,
        ]);
    }
}
