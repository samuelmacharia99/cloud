<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDoctorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Doctor could say a Python container was crash-looping and nothing more.
 *
 * The presenter that reads a pydantic validation error, an empty setting or an
 * unimportable module was wired into the deploy and pull paths only, so the one
 * screen a customer opens when their site is down was the one place that could
 * not tell them why it was down.
 */
class ContainerDoctorRuntimeCrashFindingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_names_the_settings_a_python_app_refused_to_start_without(): void
    {
        $finding = $this->finding('python', <<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 2 validation errors for Settings
        ENABLE_SMS
          Input should be a valid boolean, unable to interpret input [type=bool_parsing, input_value='', input_type=str]
        RIDER_LOCATION_MAX_AGE_SECONDS
          Input should be a valid integer, unable to parse string as an integer [type=int_parsing, input_value='', input_type=str]
        LOG, ['restarting' => true]);

        $this->assertNotNull($finding);
        $this->assertSame('runtime_crash_cause', $finding['id']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertStringContainsString('ENABLE_SMS', implode(' ', $finding['evidence']));
        $this->assertStringContainsString('present but empty', $finding['summary']);
    }

    #[Test]
    public function it_names_an_unimportable_module(): void
    {
        $finding = $this->finding(
            'python',
            "Traceback (most recent call last):\nModuleNotFoundError: No module named 'app.settings'",
            ['restarting' => true],
        );

        $this->assertNotNull($finding);
        $this->assertStringContainsString('app.settings', $finding['summary']);
        $this->assertSame('restart_application', $finding['treat_action']);
    }

    #[Test]
    public function a_log_it_cannot_read_leaves_the_existing_findings_alone(): void
    {
        // Repeating "crash-looping" back with no more insight would be noise
        // wearing the clothes of a diagnosis.
        $this->assertNull($this->finding('python', "something went wrong\nand again\n", ['restarting' => true]));
    }

    #[Test]
    public function a_container_that_is_up_is_not_diagnosed_from_an_old_traceback(): void
    {
        // The six-hour log window keeps a traceback long after the deploy that
        // fixed it. A finding built from one of those sends somebody after a
        // problem they already solved.
        $log = "pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings\n"
            ."ENABLE_SMS\n  Input should be a valid boolean [type=bool_parsing, input_value='', input_type=str]\n";

        $this->assertNull($this->finding('python', $log, ['restarting' => false, 'running' => true]));
        $this->assertNotNull($this->finding('python', $log, ['restarting' => false, 'running' => false]));
    }

    #[Test]
    public function a_memory_kill_is_left_to_the_finding_that_understands_memory(): void
    {
        $log = "MemoryError\npydantic_core._pydantic_core.ValidationError: 1 validation error for Settings\n"
            ."ENABLE_SMS\n  Input should be a valid boolean [type=bool_parsing, input_value='', input_type=str]\n";

        $this->assertNull($this->finding('python', $log, ['restarting' => true, 'oom' => true]));
    }

    #[Test]
    public function stacks_with_a_check_of_their_own_are_not_routed_here(): void
    {
        $log = "Traceback (most recent call last):\nModuleNotFoundError: No module named 'app'";

        $this->assertNull($this->finding('laravel', $log, ['restarting' => true]));
        $this->assertNull($this->finding('nodejs', $log, ['restarting' => true]));
        $this->assertNull($this->finding('wordpress', $log, ['restarting' => true]));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function finding(string $stack, string $logs, array $snapshot): ?array
    {
        $method = new \ReflectionMethod(ContainerDoctorService::class, 'runtimeCrashFinding');

        return $method->invoke(
            app(ContainerDoctorService::class),
            $stack,
            $logs,
            $this->deployment(),
            $snapshot,
        );
    }

    private function deployment(): ContainerDeployment
    {
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'python'],
        ]);

        return ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python-'.uniqid(),
            'status' => 'running',
        ]);
    }
}
