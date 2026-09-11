<?php

namespace Tests\Unit\Provisioning;

use App\Exceptions\ApplicationConfigurationRequiredException;
use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerApplicationReadinessService;
use App\Services\Provisioning\ContainerRuntimeCrashReader;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Service 457 pulled successfully, reported every step green, and was dead.
 *
 * The check these stacks had returned on the first "running" Docker reported,
 * and a crash-looping container is running, briefly, between restarts. Laravel
 * proved itself over HTTP and Node had a readiness probe. Python, Ruby and Go
 * had a coin toss.
 */
class ContainerApplicationReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_container_that_is_up_and_stays_up_passes(): void
    {
        [$service, $deployment] = $this->pythonService();

        $this->readiness()->assertReady(
            $this->ssh(['running|true|false|0|false|3']),
            $service,
            $deployment,
        );

        $this->assertTrue($this->recorded($service, 'application_readiness_passed'));
    }

    #[Test]
    public function a_container_that_restarts_while_we_watch_is_not_ready(): void
    {
        // The whole point. Every one of these reads says "running", and the
        // restart count is the only thing that says the app is not staying up.
        [$service, $deployment] = $this->pythonService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not staying up');

        $this->readiness()->assertReady(
            $this->ssh([
                'running|true|false|0|false|4',
                'running|true|false|0|false|5',
                'running|true|false|0|false|6',
            ]),
            $service,
            $deployment,
            timeoutSeconds: 5,
        );
    }

    #[Test]
    public function a_crash_that_names_variables_parks_the_stack_instead_of_failing_it(): void
    {
        [$service, $deployment] = $this->pythonService();
        $this->crashReaderReturning([
            'message' => 'The application could not start because required environment variables are missing: NES_API_KEY.',
            'missing_variables' => ['NES_API_KEY'],
            'recognised' => true,
        ]);

        try {
            $this->readiness()->assertReady(
                $this->ssh(['restarting|false|false|1|true|9']),
                $service,
                $deployment,
                timeoutSeconds: 5,
            );
            $this->fail('A missing credential must not be reported as a platform failure.');
        } catch (ApplicationConfigurationRequiredException $e) {
            $this->assertSame(['NES_API_KEY'], $e->missingVariables());
        }

        $this->assertTrue($this->recorded($service, 'application_readiness_awaiting_configuration'));
    }

    #[Test]
    public function a_crash_with_no_named_variables_fails_and_carries_the_cause(): void
    {
        [$service, $deployment] = $this->pythonService();
        $this->crashReaderReturning([
            'message' => 'ModuleNotFoundError: No module named \'app.settings\'',
            'missing_variables' => [],
            'recognised' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ModuleNotFoundError');

        $this->readiness()->assertReady(
            $this->ssh(['restarting|false|false|1|true|9']),
            $service,
            $deployment,
            timeoutSeconds: 5,
        );
    }

    #[Test]
    public function a_memory_kill_is_reported_as_memory_and_not_as_a_bug(): void
    {
        // Sending somebody to debug their code for an OOM wastes their evening.
        [$service, $deployment] = $this->pythonService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('more memory than its plan allows');

        $this->readiness()->assertReady(
            $this->ssh(['exited|false|true|137|false|2']),
            $service,
            $deployment,
            timeoutSeconds: 5,
        );
    }

    #[Test]
    public function a_container_that_is_not_on_the_node_says_so(): void
    {
        [$service, $deployment] = $this->pythonService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not on the node');

        $this->readiness()->assertReady($this->ssh(['']), $service, $deployment, timeoutSeconds: 5);
    }

    #[Test]
    public function a_failure_is_recorded_on_the_service_timeline(): void
    {
        [$service, $deployment] = $this->pythonService();

        try {
            $this->readiness()->assertReady(
                $this->ssh(['exited|false|true|137|false|2']),
                $service,
                $deployment,
                timeoutSeconds: 5,
            );
        } catch (\RuntimeException) {
            // The exception is the subject of another test.
        }

        $this->assertTrue($this->recorded($service, 'application_readiness_started'));
        $this->assertTrue($this->recorded($service, 'application_readiness_failed'));
    }

    #[Test]
    public function only_the_stacks_with_no_check_of_their_own_are_routed_here(): void
    {
        $readiness = $this->readiness();

        $this->assertTrue($readiness->supports('python'));
        $this->assertTrue($readiness->supports('ruby'));
        $this->assertTrue($readiness->supports('go'));

        // Laravel proves itself over HTTP and Node has its own probe. Routing
        // either through here would replace a stronger check with a weaker one.
        $this->assertFalse($readiness->supports('laravel'));
        $this->assertFalse($readiness->supports('nodejs'));
        $this->assertFalse($readiness->supports('wordpress'));
        $this->assertFalse($readiness->supports(null));
    }

    private function readiness(): ContainerApplicationReadinessService
    {
        return app(ContainerApplicationReadinessService::class);
    }

    /**
     * @param  array<string, mixed>  $crash
     */
    private function crashReaderReturning(array $crash): void
    {
        $reader = Mockery::mock(ContainerRuntimeCrashReader::class);
        $reader->shouldReceive('read')->andReturn($crash);
        $this->app->instance(ContainerRuntimeCrashReader::class, $reader);
    }

    /**
     * A node answering `docker inspect` with the given lines in turn, repeating
     * the last one once the script runs out.
     *
     * @param  list<string>  $inspectLines
     */
    private function ssh(array $inspectLines): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(
            function (string $command) use (&$inspectLines): string {
                if (! str_contains($command, 'docker inspect')) {
                    return '';
                }

                return count($inspectLines) > 1
                    ? (string) array_shift($inspectLines)
                    : (string) ($inspectLines[0] ?? '');
            }
        );

        return $ssh;
    }

    private function recorded(Service $service, string $event): bool
    {
        return ContainerDeploymentEvent::where('service_id', $service->id)
            ->where('event', $event)
            ->exists();
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function pythonService(): array
    {
        $template = ContainerTemplate::factory()->create(['slug' => 'python']);
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => $template->slug],
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-1-service-457-python',
            'status' => 'running',
        ]);

        return [$service, $deployment];
    }
}
