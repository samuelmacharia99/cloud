<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use App\Services\Provisioning\ContainerEnvironmentService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The Environment tab kept asking for variables the customer had already
 * deleted from their code.
 *
 * Discovery ran once, inside the first deploy, and never again. So a service
 * created in March described March's example file forever, and a customer
 * looking at the list had no way to tell which of those names their application
 * still wanted. The checkout that was just synced is the only current answer.
 */
class ContainerPullEnvironmentRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_variable_added_to_the_example_file_appears_after_a_pull(): void
    {
        [$service, $deployment] = $this->pythonService();

        $this->refresh($service, $deployment, "NES_API_KEY=\nMPESA_SHORTCODE=\n");

        $declared = app(ApplicationEnvironmentRequirements::class)->declared($service->fresh());

        $this->assertContains('NES_API_KEY', $declared);
        $this->assertContains('MPESA_SHORTCODE', $declared);
    }

    public function test_a_variable_deleted_from_the_code_stops_being_asked_for(): void
    {
        [$service, $deployment] = $this->pythonService();
        app(ApplicationEnvironmentRequirements::class)
            ->rememberDeclared($service, ['NES_API_KEY', 'MPESA_SHORTCODE']);

        // The customer dropped the M-Pesa integration and pulled.
        $this->refresh($service->fresh(), $deployment, "NES_API_KEY=\n");

        $declared = app(ApplicationEnvironmentRequirements::class)->declared($service->fresh());

        $this->assertSame(['NES_API_KEY'], $declared);
    }

    public function test_the_environment_tab_stops_offering_the_deleted_variable(): void
    {
        [$service, $deployment] = $this->pythonService();
        app(ApplicationEnvironmentRequirements::class)
            ->rememberDeclared($service, ['NES_API_KEY', 'MPESA_SHORTCODE']);

        $this->refresh($service->fresh(), $deployment, "NES_API_KEY=\n");

        $suggested = app(ContainerEnvironmentService::class)
            ->buildPanelState($service->fresh(), $deployment->fresh())['suggested_by_app'];

        $this->assertContains('NES_API_KEY', $suggested);
        $this->assertNotContains('MPESA_SHORTCODE', $suggested);
    }

    public function test_the_step_reports_what_changed_rather_than_a_bare_success(): void
    {
        [$service, $deployment] = $this->pythonService();
        app(ApplicationEnvironmentRequirements::class)
            ->rememberDeclared($service, ['MPESA_SHORTCODE']);

        $message = $this->refresh($service->fresh(), $deployment, "NES_API_KEY=\n");

        $this->assertStringContainsString('New since the last pull: NES_API_KEY', $message);
        $this->assertStringContainsString('No longer asked for: MPESA_SHORTCODE', $message);
    }

    public function test_a_repository_with_no_example_file_says_so_plainly(): void
    {
        [$service, $deployment] = $this->pythonService();

        $message = $this->refresh($service, $deployment, '');

        $this->assertStringContainsString('No example environment file', $message);
    }

    public function test_the_refresh_is_recorded_on_the_service_timeline(): void
    {
        [$service, $deployment] = $this->pythonService();

        $this->refresh($service, $deployment, "NES_API_KEY=\n");

        $this->assertTrue(
            ContainerDeploymentEvent::where('service_id', $service->id)
                ->where('event', 'application_environment_keys_refreshed')
                ->exists()
        );
    }

    public function test_a_value_is_never_filled_in_for_the_customer(): void
    {
        // A blank would satisfy a "required string" check and turn a clean stop
        // into an application that boots looking healthy and misbehaves later.
        [$service, $deployment] = $this->pythonService();

        $this->refresh($service, $deployment, "NES_API_KEY=\n");

        $this->assertArrayNotHasKey('NES_API_KEY', (array) $deployment->fresh()->env_values);
    }

    private function refresh(Service $service, ContainerDeployment $deployment, string $exampleFile): string
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn($exampleFile);

        $method = new \ReflectionMethod(ContainerGitRepositoryService::class, 'refreshDeclaredEnvironment');

        return (string) $method->invoke(app(ContainerGitRepositoryService::class), $service, $deployment, $ssh);
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
            'env_values' => ['DB_HOST' => 'user-1-service-457-python-db'],
        ]);

        return [$service, $deployment];
    }
}
