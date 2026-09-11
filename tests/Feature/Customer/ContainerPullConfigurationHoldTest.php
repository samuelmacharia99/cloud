<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Exceptions\ApplicationConfigurationRequiredException;
use App\Models\ContainerDeployment;
use App\Models\ContainerGitPull;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A pull that brings down code the application cannot start without.
 *
 * The repository synced fine; what is missing are credentials only the customer
 * holds. A deploy already parks the stack for that. A pull used to hand back a
 * traceback under a failed step, which reads as a platform fault and invites a
 * retry that cannot succeed until the values are set.
 */
class ContainerPullConfigurationHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pull_that_needs_configuration_parks_the_service(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress();

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $this->ssh(),
            new ApplicationConfigurationRequiredException(['MPESA_SHORTCODE', 'NES_API_KEY']),
        );

        $this->assertSame(ServiceStatus::AwaitingConfiguration, $service->fresh()->status);
        $this->assertSame('stopped', $deployment->fresh()->status);
    }

    public function test_the_missing_variables_are_recorded_for_the_environment_tab(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress();

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $this->ssh(),
            new ApplicationConfigurationRequiredException(['MPESA_SHORTCODE', 'NES_API_KEY']),
        );

        $outstanding = app(ApplicationEnvironmentRequirements::class)
            ->outstandingRequired($service->fresh(), $deployment->fresh());

        $this->assertContains('MPESA_SHORTCODE', $outstanding);
        $this->assertContains('NES_API_KEY', $outstanding);
    }

    public function test_the_pull_names_the_variables_instead_of_a_traceback(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress();

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $this->ssh(),
            new ApplicationConfigurationRequiredException(['MPESA_SHORTCODE']),
        );

        $pull->refresh();

        $this->assertSame(ContainerGitPull::STATUS_FAILED, $pull->status);
        $this->assertStringContainsString('MPESA_SHORTCODE', (string) $pull->error_message);
        $this->assertStringNotContainsString('Traceback', (string) $pull->error_message);
    }

    public function test_a_failure_to_park_the_stack_never_loses_the_cause(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andThrow(new \RuntimeException('node unreachable'));
        $ssh->shouldReceive('upload')->andThrow(new \RuntimeException('node unreachable'));
        $ssh->shouldReceive('mkdirp')->andThrow(new \RuntimeException('node unreachable'));

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $ssh,
            new ApplicationConfigurationRequiredException(['MPESA_SHORTCODE']),
        );

        $pull->refresh();

        $this->assertSame(ContainerGitPull::STATUS_FAILED, $pull->status);
        $this->assertStringContainsString('MPESA_SHORTCODE', (string) $pull->error_message);
    }

    public function test_a_variable_the_application_stopped_needing_is_forgotten(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress();
        $requirements = app(ApplicationEnvironmentRequirements::class);

        $requirements->rememberRequired($service, ['MPESA_SHORTCODE', 'NES_API_KEY']);

        // The customer deleted the M-Pesa integration and pulled. The next hold
        // reports only what is left, and that is the whole list now.
        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service->fresh(),
            $deployment,
            $this->ssh(),
            new ApplicationConfigurationRequiredException(['NES_API_KEY']),
        );

        $outstanding = $requirements->outstandingRequired($service->fresh(), $deployment->fresh());

        $this->assertSame(['NES_API_KEY'], $outstanding);
        $this->assertNotContains('MPESA_SHORTCODE', $outstanding);
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment, 2: ContainerGitPull}
     */
    private function pullInProgress(): array
    {
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $pull = ContainerGitPull::create([
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'user_id' => $customer->id,
            'template_slug' => 'python',
            'status' => ContainerGitPull::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return [$service, $deployment, $pull];
    }

    private function ssh(): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn('');
        $ssh->shouldReceive('upload')->andReturn(true);
        $ssh->shouldReceive('mkdirp')->andReturn(true);

        return $ssh;
    }
}
