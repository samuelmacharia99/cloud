<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDoctorService;
use App\Services\ResellerAnalyticsService;
use App\Services\ResellerHostingHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A reseller who sells application hosting instead of DirectAdmin.
 *
 * The portal used to treat that reseller as half set up forever, show them no
 * infrastructure at all, and give them no way to answer a support question
 * about a customer's application without impersonating them.
 */
class ResellerHostingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_completes_for_a_reseller_who_sells_only_application_hosting(): void
    {
        $reseller = $this->reseller();
        $this->catalogListing($reseller, 'container_hosting');

        $onboarding = app(ResellerAnalyticsService::class)
            ->onboardingChecklist($reseller, hasDa: false, unlinkedDaCount: 0);

        $catalogStep = collect($onboarding['steps'])->firstWhere('key', 'catalog');
        $this->assertTrue($catalogStep['done'], 'An application hosting listing is a hosting catalogue.');

        $directAdminStep = collect($onboarding['steps'])->firstWhere('key', 'directadmin');
        $this->assertTrue($directAdminStep['optional'], 'DirectAdmin cannot be required of a reseller who does not use it.');

        $this->assertSame(4, $onboarding['total'], 'The two DirectAdmin steps drop out of the required count.');
    }

    public function test_shared_hosting_still_completes_the_catalogue_step(): void
    {
        $reseller = $this->reseller();
        $this->catalogListing($reseller, 'shared_hosting');

        $onboarding = app(ResellerAnalyticsService::class)
            ->onboardingChecklist($reseller, hasDa: true, unlinkedDaCount: 0);

        $this->assertTrue(collect($onboarding['steps'])->firstWhere('key', 'catalog')['done']);
        $this->assertFalse(collect($onboarding['steps'])->firstWhere('key', 'directadmin')['optional']);
    }

    public function test_a_non_hosting_listing_does_not_complete_the_catalogue_step(): void
    {
        $reseller = $this->reseller();
        $this->catalogListing($reseller, 'vps');

        $onboarding = app(ResellerAnalyticsService::class)
            ->onboardingChecklist($reseller, hasDa: false, unlinkedDaCount: 0);

        $this->assertFalse(collect($onboarding['steps'])->firstWhere('key', 'catalog')['done']);
    }

    public function test_the_dashboard_reports_an_application_that_is_not_running(): void
    {
        $reseller = $this->reseller();
        $this->containerService($reseller, serviceStatus: 'active', deploymentStatus: 'stopped');

        $health = app(ResellerHostingHealthService::class)->snapshot($reseller);
        $this->assertSame(1, $health['containers_down']);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 application(s) not running');
    }

    public function test_a_redeploy_in_progress_is_not_reported_as_an_outage(): void
    {
        $reseller = $this->reseller();
        $this->containerService($reseller, serviceStatus: 'active', deploymentStatus: 'deploying');

        $this->assertSame(0, app(ResellerHostingHealthService::class)->snapshot($reseller)['containers_down']);
    }

    public function test_a_stopped_container_under_a_suspended_service_is_not_an_outage(): void
    {
        $reseller = $this->reseller();
        $this->containerService($reseller, serviceStatus: 'suspended', deploymentStatus: 'stopped');

        $health = app(ResellerHostingHealthService::class)->snapshot($reseller);
        $this->assertSame(0, $health['containers_down']);
        $this->assertSame(1, $health['suspended_services']);
    }

    public function test_the_dashboard_reports_a_service_that_failed_to_provision(): void
    {
        $reseller = $this->reseller();
        $this->containerService($reseller, serviceStatus: 'failed', deploymentStatus: 'failed');

        $this->assertSame(1, app(ResellerHostingHealthService::class)->snapshot($reseller)['failed_services']);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 customer service(s) failed to provision');
    }

    public function test_health_counts_only_this_resellers_own_customers(): void
    {
        $reseller = $this->reseller();
        $stranger = $this->reseller();
        $this->containerService($stranger, serviceStatus: 'active', deploymentStatus: 'stopped');

        $health = app(ResellerHostingHealthService::class)->snapshot($reseller);

        $this->assertSame(0, $health['containers_down']);
        $this->assertSame(0, $health['total_services']);
    }

    public function test_the_managing_reseller_can_diagnose_a_customers_application(): void
    {
        $reseller = $this->reseller();
        $service = $this->containerService($reseller);

        $this->mock(ContainerDoctorService::class, function (MockInterface $mock) {
            $mock->shouldReceive('diagnose')->once()->andReturn([
                'scanned_at' => now()->toIso8601String(),
                'findings' => [],
                'live_checks' => ['http_status' => 200],
                'healthy' => true,
            ]);
        });

        $this->actingAs($reseller)
            ->postJson(route('reseller.services.diagnose', $service))
            ->assertOk()
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('live_checks.http_status', 200);
    }

    public function test_a_foreign_reseller_cannot_diagnose_this_application(): void
    {
        $reseller = $this->reseller();
        $stranger = $this->reseller();
        $service = $this->containerService($reseller);

        $this->mock(ContainerDoctorService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('diagnose');
        });

        $this->actingAs($stranger)
            ->postJson(route('reseller.services.diagnose', $service))
            ->assertNotFound();
    }

    public function test_diagnosis_never_offers_a_repair(): void
    {
        $reseller = $this->reseller();
        $service = $this->containerService($reseller);

        $response = $this->actingAs($reseller)->get(route('reseller.services.show', $service));

        $response->assertOk()
            ->assertSee('Application diagnosis')
            ->assertSee('resellerApplicationDiagnosis', false)
            ->assertDontSee('container/doctor/treat')
            ->assertDontSee('container\/doctor\/treat', false);
    }

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter '.uniqid(),
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_services' => 25,
            'max_users' => 10,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now(),
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function catalogListing(User $reseller, string $type): ResellerProduct
    {
        return ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Plan '.uniqid(),
            'type' => $type,
            'monthly_price' => 1500,
            'is_active' => true,
        ]);
    }

    private function containerService(
        User $reseller,
        string $serviceStatus = 'active',
        string $deploymentStatus = 'running',
    ): Service {
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->containerHosting()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => $serviceStatus,
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => $deploymentStatus,
        ]);

        return $service;
    }
}
