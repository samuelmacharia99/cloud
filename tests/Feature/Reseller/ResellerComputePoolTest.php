<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerDeployment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerCheckoutGuardService;
use App\Services\ResellerComputeUsageService;
use App\Services\ResellerDiskUsageService;
use App\Services\ResellerEnforcementService;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Application hosting plans reserve node CPU and memory that no reseller
 * package counted, so the product line had no ceiling and no visible cost.
 *
 * This round meters and refuses. It deliberately does not bill, and one of the
 * tests below is there to keep it that way.
 */
class ResellerComputePoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_sums_the_limits_written_onto_each_deployment(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $this->containerService($reseller, cpu: 1.0, memoryMb: 512);
        $this->containerService($reseller, cpu: 2.0, memoryMb: 2048);

        $allocation = app(ResellerComputeUsageService::class)->collectCurrentAllocation($reseller);

        $this->assertSame(3.0, $allocation['cpu_cores']);
        $this->assertSame(2560, $allocation['memory_mb']);
        $this->assertSame(2, $allocation['service_count']);
    }

    public function test_allocation_counts_only_this_resellers_own_services(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $stranger = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $this->containerService($stranger, cpu: 4.0, memoryMb: 8192);

        $this->assertSame(0.0, app(ResellerComputeUsageService::class)->collectCurrentAllocation($reseller)['cpu_cores']);
    }

    public function test_allocation_counts_a_service_linked_only_through_its_customer(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $this->containerService($reseller, cpu: 1.5, memoryMb: 1024, tagService: false);

        $this->assertSame(1.5, app(ResellerComputeUsageService::class)->collectCurrentAllocation($reseller)['cpu_cores']);
    }

    public function test_a_terminated_service_releases_its_allocation(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $this->containerService($reseller, cpu: 2.0, memoryMb: 2048, serviceStatus: 'terminated');

        $this->assertSame(0.0, app(ResellerComputeUsageService::class)->collectCurrentAllocation($reseller)['cpu_cores']);
    }

    public function test_pool_presentation_reports_remaining_and_percentage(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 4, 'memory_pool_mb' => 8192]);
        $this->containerService($reseller, cpu: 1.0, memoryMb: 2048);

        $pool = app(ResellerComputeUsageService::class)->poolPresentation($reseller);

        $this->assertSame(4.0, $pool['cpu']['pool']);
        $this->assertSame(1.0, $pool['cpu']['used']);
        $this->assertSame(3.0, $pool['cpu']['remaining']);
        $this->assertSame(25.0, $pool['cpu']['percent']);
        $this->assertSame(2048, $pool['memory']['used']);
        $this->assertSame(6144, $pool['memory']['remaining']);
        $this->assertSame(25.0, $pool['memory']['percent']);
    }

    public function test_a_package_with_no_pool_is_unmetered(): void
    {
        $reseller = $this->reseller();
        $this->containerService($reseller, cpu: 99.0, memoryMb: 999999);

        $compute = app(ResellerComputeUsageService::class);
        $pool = $compute->poolPresentation($reseller);

        $this->assertFalse($pool['metered']);
        $this->assertNull($pool['cpu']['percent']);
        $this->assertNull($pool['memory']['percent']);
        $this->assertFalse($compute->isOverPool($reseller));
        $this->assertTrue($compute->checkHeadroom($reseller, 64, 65536)['allowed']);
    }

    public function test_headroom_refuses_a_plan_that_would_not_fit(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 4, 'memory_pool_mb' => 8192]);
        $this->containerService($reseller, cpu: 3.0, memoryMb: 6144);

        $compute = app(ResellerComputeUsageService::class);

        $this->assertTrue($compute->checkHeadroom($reseller, 1.0, 2048)['allowed'], 'Exactly filling the pool is allowed.');
        $this->assertFalse($compute->checkHeadroom($reseller, 2.0, 2048)['allowed']);

        $this->expectException(\InvalidArgumentException::class);
        $compute->assertHeadroom($reseller, 2.0, 4096);
    }

    public function test_the_daily_snapshot_does_not_clobber_the_disk_columns(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 4, 'memory_pool_mb' => 8192, 'disk_pool_gb' => 50]);
        $this->containerService($reseller, cpu: 2.0, memoryMb: 4096);

        app(ResellerDiskUsageService::class)->recordDailySnapshot($reseller);
        app(ResellerComputeUsageService::class)->recordDailySnapshot($reseller);

        $snapshot = ResellerDiskUsageSnapshot::where('reseller_id', $reseller->id)->firstOrFail();

        $this->assertSame(2.0, (float) $snapshot->cpu_cores_allocated);
        $this->assertSame(4096, (int) $snapshot->memory_mb_allocated);
        $this->assertNotNull($snapshot->total_used_gb, 'The disk collector kept its own columns.');
        $this->assertSame(1, ResellerDiskUsageSnapshot::where('reseller_id', $reseller->id)->count());
    }

    public function test_provisioning_is_blocked_once_the_pool_is_exceeded(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 1, 'memory_pool_mb' => 1024]);
        $service = $this->containerService($reseller, cpu: 4.0, memoryMb: 8192);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('compute pool');

        app(ResellerEnforcementService::class)->assertCanProvision($service->fresh());
    }

    public function test_a_renewal_invoice_never_bills_for_compute(): void
    {
        $reseller = $this->reseller([
            'cpu_pool_cores' => 1,
            'memory_pool_mb' => 1024,
            'disk_pool_gb' => 50,
            'disk_overage_rate' => 25,
        ]);
        $this->containerService($reseller, cpu: 8.0, memoryMb: 16384);
        app(ResellerComputeUsageService::class)->recordDailySnapshot($reseller);

        $invoice = app(ResellerPackageSubscriptionService::class)
            ->createSubscriptionInvoice($reseller, $reseller->resellerPackage, renewal: true);

        $productTypes = Invoice::find($invoice->id)->items->pluck('product_type')->all();

        $this->assertNotContains('reseller_compute_usage', $productTypes);
        $this->assertNotContains('reseller_compute_overage', $productTypes);
        $this->assertEmpty(
            array_filter($productTypes, fn ($type) => str_contains((string) $type, 'compute')),
            'Metering compute must not start billing for it.'
        );
    }

    public function test_a_storefront_order_is_refused_when_the_providers_pool_is_full(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 2, 'memory_pool_mb' => 2048]);
        $this->containerService($reseller, cpu: 2.0, memoryMb: 2048);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $listing = $this->catalogListing($reseller, cpu: 1.0, memoryMb: 1024);

        $this->expectException(\InvalidArgumentException::class);
        // The provider's own numbers stay out of a message their customer reads.
        $this->expectExceptionMessage('Your provider does not have enough capacity');

        app(ResellerCheckoutGuardService::class)->assertCartAllowed($customer, [
            'item' => ['type' => 'reseller_product', 'reseller_product_id' => $listing->id, 'quantity' => 1],
        ]);
    }

    public function test_a_storefront_order_that_fits_is_allowed(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $this->containerService($reseller, cpu: 2.0, memoryMb: 2048);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $listing = $this->catalogListing($reseller, cpu: 1.0, memoryMb: 1024);

        app(ResellerCheckoutGuardService::class)->assertCartAllowed($customer, [
            'item' => ['type' => 'reseller_product', 'reseller_product_id' => $listing->id, 'quantity' => 1],
        ]);

        $this->assertTrue(true, 'A cart within the pool raises nothing.');
    }

    public function test_the_dashboard_shows_the_pool_meters(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 4, 'memory_pool_mb' => 8192]);
        $this->containerService($reseller, cpu: 1.0, memoryMb: 2048);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('vCPU pool')
            ->assertSee('1.00 / 4')
            ->assertSee('RAM pool')
            ->assertSee('2.0 / 8.0 GB');
    }

    public function test_a_reseller_without_a_pool_sees_no_meter(): void
    {
        $reseller = $this->reseller();
        $this->containerService($reseller, cpu: 1.0, memoryMb: 2048);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('vCPU pool');
    }

    private function reseller(array $packageOverrides = []): User
    {
        $package = ResellerPackage::create(array_merge([
            'name' => 'Compute '.uniqid(),
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'disk_pool_gb' => 100,
            'max_services' => 25,
            'max_users' => 25,
            'price' => 1000,
            'active' => true,
        ], $packageOverrides));

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now()->subMonth(),
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function containerService(
        User $reseller,
        float $cpu,
        int $memoryMb,
        string $serviceStatus = 'active',
        bool $tagService = true,
    ): Service {
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->containerHosting()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $tagService ? $reseller->id : null,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => $serviceStatus,
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
            'cpu_limit' => $cpu,
            'memory_limit_mb' => $memoryMb,
        ]);

        return $service;
    }

    private function catalogListing(User $reseller, float $cpu, int $memoryMb): ResellerProduct
    {
        return ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Plan '.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 2000,
            'is_active' => true,
            'resource_limits' => ['cpu' => $cpu, 'memory_mb' => $memoryMb, 'disk_gb' => 10],
        ]);
    }
}
