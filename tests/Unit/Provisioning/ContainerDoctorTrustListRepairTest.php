<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDoctorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The customer saw fifteen frames of importlib and one line naming a pydantic
 * internal. Nothing in that traceback contains the word "environment", so there
 * was no way to know the fix was one field in the Environment tab, let alone
 * what to put in it. The platform knew: the domains bound to that very service.
 */
class ContainerDoctorTrustListRepairTest extends TestCase
{
    use RefreshDatabase;

    private const CRASH = 'pydantic_settings.exceptions.SettingsError: error parsing value for field '
        .'"ALLOWED_ORIGINS" from source "EnvSettingsSource"';

    #[Test]
    public function the_finding_carries_the_value_and_not_only_the_complaint(): void
    {
        [, $deployment] = $this->pythonService('chakula.co.ke');

        $finding = $this->finding('python', self::CRASH, $deployment);

        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame(ContainerDoctorService::FIX_TRUST_LIST_ACTION, $finding['treat_action']);
        $this->assertStringContainsString('ALLOWED_ORIGINS=["https://chakula.co.ke"', $finding['summary']);
    }

    #[Test]
    public function nothing_is_offered_when_the_platform_has_no_domain_to_answer_with(): void
    {
        // Offering a repair that would write nothing is worse than offering
        // none: the customer clicks it, sees success, and is no better off.
        [, $deployment] = $this->pythonService(null);

        $this->assertNull($this->finding('python', self::CRASH, $deployment));
    }

    #[Test]
    public function a_setting_outside_the_two_known_families_is_not_claimed(): void
    {
        [, $deployment] = $this->pythonService('chakula.co.ke');

        $crash = 'pydantic_settings.exceptions.SettingsError: error parsing value for field '
            .'"RATE_LIMIT_TIERS" from source "EnvSettingsSource"';

        $this->assertNull($this->finding('python', $crash, $deployment));
    }

    #[Test]
    public function a_healthy_log_produces_no_finding(): void
    {
        [, $deployment] = $this->pythonService('chakula.co.ke');

        $this->assertNull($this->finding('python', "INFO: Uvicorn running on http://0.0.0.0:8000\n", $deployment));
        $this->assertNull($this->finding('python', '', $deployment));
    }

    #[Test]
    public function the_repair_writes_the_value_and_recreates_the_application(): void
    {
        [$service, $deployment] = $this->pythonService('chakula.co.ke');
        $deployment->update(['env_values' => ['ALLOWED_ORIGINS' => 'chakula.co.ke,www.chakula.co.ke']]);

        $deployments = Mockery::mock(ContainerDeploymentService::class)->makePartial();
        $deployments->shouldReceive('applyEnvironmentVariables')->once();
        $this->app->instance(ContainerDeploymentService::class, $deployments);

        $result = $this->repair($service->fresh());

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(
            '["https://chakula.co.ke","https://www.chakula.co.ke"]',
            $deployment->fresh()->env_values['ALLOWED_ORIGINS'],
        );
    }

    #[Test]
    public function the_repair_leaves_every_other_setting_alone(): void
    {
        [$service, $deployment] = $this->pythonService('chakula.co.ke');
        $deployment->update(['env_values' => [
            'ALLOWED_ORIGINS' => 'bad',
            'MPESA_SHORTCODE' => '174379',
            'DB_HOST' => 'user-493-service-457-python-db',
        ]]);

        $deployments = Mockery::mock(ContainerDeploymentService::class)->makePartial();
        $deployments->shouldReceive('applyEnvironmentVariables')->once();
        $this->app->instance(ContainerDeploymentService::class, $deployments);

        $this->repair($service->fresh());

        $env = $deployment->fresh()->env_values;

        $this->assertSame('174379', $env['MPESA_SHORTCODE']);
        $this->assertSame('user-493-service-457-python-db', $env['DB_HOST']);
    }

    #[Test]
    public function the_repair_refuses_rather_than_reporting_a_success_that_changed_nothing(): void
    {
        // Through treat() rather than the method directly, so this also proves
        // the action is dispatchable and is not refused for being applied to a
        // container that is crash-looping, which by definition it is.
        [$service] = $this->pythonService(null);

        $result = app(ContainerDoctorService::class)
            ->treat($service->fresh(), ContainerDoctorService::FIX_TRUST_LIST_ACTION);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no live domain', $result['message']);
    }

    /**
     * The repair on its own. treat() re-runs a full diagnosis over SSH after a
     * successful action, which a unit test has no node to answer.
     *
     * @return array{success: bool, message: string}
     */
    private function repair(Service $service): array
    {
        $method = new \ReflectionMethod(ContainerDoctorService::class, 'treatFixTrustListSettings');

        return $method->invoke(app(ContainerDoctorService::class), $service);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function finding(string $stack, string $logs, ContainerDeployment $deployment): ?array
    {
        $method = new \ReflectionMethod(ContainerDoctorService::class, 'unreadableTrustListFinding');

        return $method->invoke(app(ContainerDoctorService::class), $stack, $logs, $deployment);
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function pythonService(?string $domain): array
    {
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
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
        ]);

        if ($domain !== null) {
            ContainerDomain::create([
                'container_deployment_id' => $deployment->id,
                'domain' => $domain,
                'purpose' => ContainerDomain::PURPOSE_WEB,
                'status' => 'active',
                'ssl_enabled' => true,
            ]);
        }

        return [$service, $deployment->fresh()];
    }
}
