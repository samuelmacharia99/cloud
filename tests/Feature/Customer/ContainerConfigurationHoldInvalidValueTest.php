<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Exceptions\ApplicationConfigurationRequiredException;
use App\Models\ContainerDeployment;
use App\Models\ContainerGitPull;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use App\Services\Provisioning\ContainerConfigurationHoldService;
use App\Services\Provisioning\ContainerEnvironmentService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\Provisioning\NodeWebGatewayProxy;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A setting the application rejected the value of used to leave the site
 * crash-looping behind a 502, because only an absent setting parked it.
 *
 * The difference mattered to the platform and not at all to the visitor, who
 * got a broken site either way, or to the customer, who holds the answer either
 * way. What broke was the reporting: every list of what a service needs is
 * built from what is unset, and these names are set. That is the whole problem
 * with them, so they were filtered out of the one page that would have said so.
 */
class ContainerConfigurationHoldInvalidValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_site_is_parked_on_a_notice_naming_a_rejected_setting(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress(['ENABLE_SMS' => 'maybe']);
        $compose = [];

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $this->ssh($compose),
            new ApplicationConfigurationRequiredException([], 'ENABLE_SMS wants true or false.', ['ENABLE_SMS']),
        );

        $this->assertSame(ServiceStatus::AwaitingConfiguration, $service->fresh()->status);
        $this->assertStringContainsString(
            NodeWebGatewayProxy::SETUP_REQUIRED_ENV.': ENABLE_SMS',
            implode("\n", $compose),
        );
    }

    public function test_the_environment_tab_flags_the_row_that_is_already_there(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress(['ENABLE_SMS' => 'maybe']);
        $compose = [];

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $this->ssh($compose),
            new ApplicationConfigurationRequiredException([], 'ENABLE_SMS wants true or false.', ['ENABLE_SMS']),
        );

        $panel = app(ContainerEnvironmentService::class)->buildPanelState($service->fresh(), $deployment->fresh());
        $row = collect($panel['variables'])->firstWhere('key', 'ENABLE_SMS');

        $this->assertSame(['ENABLE_SMS'], $panel['rejected_by_app']);
        $this->assertTrue($row['rejected_by_app']);
        // Not duplicated as an unset suggestion: it has a value, wrong but real.
        $this->assertSame('maybe', $row['value']);
        $this->assertNotContains('ENABLE_SMS', $panel['required_by_app']);
    }

    public function test_both_kinds_reach_the_notice_together(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress(['ENABLE_SMS' => 'maybe']);
        app(ApplicationEnvironmentRequirements::class)->rememberRequired($service, ['NES_API_KEY']);
        $compose = [];

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service->fresh(),
            $deployment,
            $this->ssh($compose),
            new ApplicationConfigurationRequiredException(['NES_API_KEY'], '', ['ENABLE_SMS']),
        );

        $notice = implode("\n", $compose);

        $this->assertStringContainsString('NES_API_KEY', $notice);
        $this->assertStringContainsString('ENABLE_SMS', $notice);
    }

    public function test_a_started_application_clears_both_lists(): void
    {
        [$service, $deployment, $pull] = $this->pullInProgress(['ENABLE_SMS' => 'maybe']);
        $compose = [];

        app(ContainerGitRepositoryService::class)->holdPullForConfiguration(
            $pull,
            $service,
            $deployment,
            $this->ssh($compose),
            new ApplicationConfigurationRequiredException(['NES_API_KEY'], '', ['ENABLE_SMS']),
        );

        app(ContainerConfigurationHoldService::class)
            ->release($service->fresh(), $deployment->fresh());

        $requirements = app(ApplicationEnvironmentRequirements::class);

        $this->assertSame([], $requirements->required($service->fresh()));
        $this->assertSame([], $requirements->invalid($service->fresh()));
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    /**
     * @param  list<string>  $compose  captures whatever is uploaded to the node
     */
    private function ssh(array &$compose): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn("services:\n  edge:\n    image: node:20-alpine\n");
        $ssh->shouldReceive('mkdirp')->andReturn(true);
        $ssh->shouldReceive('upload')->andReturnUsing(function (string $content) use (&$compose): bool {
            $compose[] = $content;

            return true;
        });

        return $ssh;
    }

    /**
     * @param  array<string, string>  $env
     * @return array{0: Service, 1: ContainerDeployment, 2: ContainerGitPull}
     */
    private function pullInProgress(array $env): array
    {
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'python'],
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python',
            'status' => 'running',
            'env_values' => $env,
        ]);

        $pull = ContainerGitPull::create([
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'user_id' => $customer->id,
            'template_slug' => 'python',
            'status' => ContainerGitPull::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return [$service->fresh(), $deployment, $pull];
    }
}
