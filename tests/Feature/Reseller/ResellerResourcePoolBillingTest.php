<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\ResellerMarginEntry;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\PublicApiCatalogSerializer;
use App\Services\ResellerBandwidthUsageService;
use App\Services\ResellerContainerRateCard;
use App\Services\ResellerCustomerOrderService;
use App\Services\ResellerMarginService;
use App\Services\ResellerPackageSubscriptionService;
use App\Services\ResellerProvisionProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reseller package is sold by resources: CPU, RAM, disk and bandwidth
 * pools, unlimited customers, backups included. What goes above a pool is
 * billed on renewal at the package's rate, and the platform's own cost per
 * unit gives every container sale a wholesale figure in the margin ledger.
 */
class ResellerResourcePoolBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_package_without_caps_has_unlimited_customers_and_services(): void
    {
        $reseller = $this->reseller(['max_users' => 0, 'max_services' => 0]);
        foreach (range(1, 3) as $i) {
            User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        }

        $this->assertFalse($reseller->resellerPackage->hasUserCap());
        $this->assertFalse($reseller->resellerPackage->hasServiceCap());
        $this->assertSame('Unlimited', $reseller->resellerPackage->userCapLabel());
        $this->assertFalse($reseller->fresh()->isAtUserLimit());
        $this->assertFalse($reseller->fresh()->isAtServiceLimit());

        $package = $this->reseller(['max_users' => 2])->resellerPackage;
        $this->assertTrue($package->hasUserCap());
        $this->assertSame('2', $package->userCapLabel());
    }

    public function test_the_public_catalogue_describes_the_package_by_its_pools(): void
    {
        $package = $this->reseller([
            'max_users' => 0,
            'max_services' => 0,
            'cpu_pool_cores' => 8,
            'memory_pool_mb' => 16384,
            'disk_pool_gb' => 200,
            'bandwidth_pool_gb' => 2000,
            'backups_included' => true,
        ])->resellerPackage;

        $data = app(PublicApiCatalogSerializer::class)->formatResellerPackage($package);

        $this->assertSame(2000, $data['bandwidth_pool_gb']);
        $this->assertTrue($data['backups_included']);
        $this->assertContains('Unlimited customers', $data['features']);
        $this->assertContains('Unlimited hosting services', $data['features']);
        $this->assertContains('2,000 GB bandwidth per month', $data['features']);
        $this->assertContains('Backups included', $data['features']);
        $this->assertContains('8 vCPU for application hosting', $data['features']);
    }

    public function test_the_reseller_package_page_shows_pools_and_free_backups(): void
    {
        $reseller = $this->reseller([
            'max_users' => 0,
            'cpu_pool_cores' => 4,
            'memory_pool_mb' => 8192,
            'bandwidth_pool_gb' => 1000,
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.packages.index'))
            ->assertOk()
            ->assertSee('Unlimited customers')
            ->assertSee('1,000 GB bandwidth / month')
            ->assertSee('Backups included')
            ->assertSee('Bandwidth');
    }

    public function test_the_rate_card_prices_a_plan_and_stays_silent_when_unset(): void
    {
        $card = app(ResellerContainerRateCard::class);
        $this->assertNull($card->monthlyWholesaleForLimits(['cpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40]));

        Setting::setValue(ResellerContainerRateCard::SETTING_CPU, '300');
        Setting::setValue(ResellerContainerRateCard::SETTING_MEMORY, '150');
        Setting::setValue(ResellerContainerRateCard::SETTING_DISK, '10');
        Setting::setValue(ResellerContainerRateCard::SETTING_BANDWIDTH, '1');

        // 2 × 300 + 4 × 150 + 40 × 10 + 100 × 1
        $this->assertSame(1700.0, $card->monthlyWholesaleForLimits(['cpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'bandwidth_gb' => 100]));

        $reseller = $this->reseller();
        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Startup',
            'type' => 'container_hosting',
            'monthly_price' => 2500,
            'is_active' => true,
            'resource_limits' => ['cpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'bandwidth_gb' => 100],
        ]);

        $this->assertSame(1700.0, $card->monthlyWholesaleForListing($listing));
        $this->assertSame(1700.0, $listing->getWholesaleMonthlyCost());
        $this->assertSame(1700.0 * 12, $listing->getWholesaleYearlyCost());
    }

    public function test_a_container_sale_carries_the_rate_card_cost_in_the_margin_ledger(): void
    {
        Setting::setValue(ResellerContainerRateCard::SETTING_CPU, '300');
        Setting::setValue(ResellerContainerRateCard::SETTING_MEMORY, '150');
        Setting::setValue(ResellerContainerRateCard::SETTING_DISK, '10');
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Startup',
            'type' => 'container_hosting',
            'monthly_price' => 2500,
            'is_active' => true,
            'resource_limits' => ['cpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
        ]);

        $result = app(ResellerCustomerOrderService::class)->orderHostingFromCatalog($reseller, $customer, $listing, 'monthly');
        $invoice = $result['invoice'];

        $this->assertSame(ResellerProvisionProductResolver::CONTAINER_SHELL_PRODUCT_SLUG, $result['service']->product->slug);
        $this->assertSame(2, (int) $result['service']->service_meta['reseller_catalog_limits']['cpu']);

        app(ResellerMarginService::class)->recordFromSettledInvoice($reseller, $invoice->fresh(['items.product', 'user']));

        $entry = ResellerMarginEntry::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(2500.0, (float) $entry->retail_amount);
        $this->assertSame(1600.0, (float) $entry->wholesale_amount, '2 × 300 + 4 × 150 + 40 × 10');
        $this->assertSame(900.0, (float) $entry->margin_amount);
    }

    public function test_renewal_bills_cpu_and_ram_above_the_pool_at_the_package_rate(): void
    {
        $reseller = $this->reseller([
            'cpu_pool_cores' => 2,
            'memory_pool_mb' => 2048,
            'disk_pool_gb' => 500,
            'cpu_overage_rate' => 400,
            'memory_overage_rate' => 100,
        ]);
        $this->containerService($reseller, cpu: 3.0, memoryMb: 4096);
        ResellerDiskUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'period_date' => now()->subDays(5)->toDateString(),
            'total_used_gb' => 1,
            'cpu_cores_allocated' => 3.0,
            'memory_mb_allocated' => 4096,
            'recorded_at' => now()->subDays(5),
        ]);

        $invoice = app(ResellerPackageSubscriptionService::class)
            ->createSubscriptionInvoice($reseller, $reseller->resellerPackage, renewal: true);

        $cpu = $invoice->items()->where('product_type', 'reseller_cpu_overage')->first();
        $ram = $invoice->items()->where('product_type', 'reseller_memory_overage')->first();

        $this->assertNotNull($cpu);
        $this->assertSame(400.0, (float) $cpu->amount, '1 vCPU over the 2 vCPU pool at KES 400');
        $this->assertNotNull($ram);
        $this->assertSame(200.0, (float) $ram->amount, '2 GB over the 2 GB pool at KES 100');
        $this->assertGreaterThanOrEqual(1000 + 600, (float) Invoice::find($invoice->id)->subtotal);
    }

    public function test_renewal_adds_no_compute_lines_without_a_rate_or_within_the_pool(): void
    {
        $unpriced = $this->reseller(['cpu_pool_cores' => 1, 'memory_pool_mb' => 1024, 'disk_pool_gb' => 500]);
        $this->containerService($unpriced, cpu: 8.0, memoryMb: 16384);

        $invoice = app(ResellerPackageSubscriptionService::class)
            ->createSubscriptionInvoice($unpriced, $unpriced->resellerPackage, renewal: true);
        $types = Invoice::find($invoice->id)->items->pluck('product_type')->all();
        $this->assertNotContains('reseller_cpu_overage', $types, 'No rate on the package or in settings means nothing to bill.');
        $this->assertNotContains('reseller_memory_overage', $types);

        $within = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384, 'disk_pool_gb' => 500, 'cpu_overage_rate' => 400, 'memory_overage_rate' => 100]);
        $this->containerService($within, cpu: 1.0, memoryMb: 1024);

        $invoice = app(ResellerPackageSubscriptionService::class)
            ->createSubscriptionInvoice($within, $within->resellerPackage, renewal: true);
        $types = Invoice::find($invoice->id)->items->pluck('product_type')->all();
        $this->assertNotContains('reseller_cpu_overage', $types);
        $this->assertNotContains('reseller_memory_overage', $types);
    }

    public function test_bandwidth_is_measured_from_container_metrics_and_billed_above_the_pool(): void
    {
        $reseller = $this->reseller(['bandwidth_pool_gb' => 100, 'bandwidth_overage_rate' => 5, 'disk_pool_gb' => 500]);
        $service = $this->containerService($reseller, cpu: 1.0, memoryMb: 1024);
        $deployment = $service->containerDeployment;
        $gb = 1024 ** 3;
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'net_io_rx_bytes' => 0,
            'net_io_tx_bytes' => 0,
            'recorded_at' => now()->subDays(20),
        ]);
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'net_io_rx_bytes' => 60 * $gb,
            'net_io_tx_bytes' => 60 * $gb,
            'recorded_at' => now()->subDays(2),
        ]);

        $bandwidth = app(ResellerBandwidthUsageService::class);
        $this->assertSame(120.0, $bandwidth->transferGbForPeriod($reseller, now()->subMonth(), now()));

        $invoice = app(ResellerPackageSubscriptionService::class)
            ->createSubscriptionInvoice($reseller, $reseller->resellerPackage, renewal: true);

        $usage = $invoice->items()->where('product_type', 'reseller_bandwidth_usage')->first();
        $over = $invoice->items()->where('product_type', 'reseller_bandwidth_overage')->first();
        $this->assertNotNull($usage);
        $this->assertSame(0.0, (float) $usage->amount, 'The usage line is informational.');
        $this->assertNotNull($over);
        $this->assertSame(100.0, (float) $over->amount, '20 GB over the 100 GB pool at KES 5');
    }

    public function test_the_reseller_dashboard_meters_bandwidth(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 4, 'memory_pool_mb' => 8192, 'bandwidth_pool_gb' => 500]);
        $this->containerService($reseller, cpu: 1.0, memoryMb: 1024);

        $this->actingAs($reseller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Bandwidth pool')
            ->assertSee('/ 500 GB');
    }

    private function reseller(array $packageOverrides = []): User
    {
        $package = ResellerPackage::create(array_merge([
            'name' => 'Pool '.uniqid(),
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'disk_pool_gb' => 100,
            'max_services' => 0,
            'max_users' => 0,
            'price' => 1000,
            'active' => true,
        ], $packageOverrides));

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now()->subMonth(),
            'package_expires_at' => now()->addDays(3),
        ]);
    }

    private function containerService(User $reseller, float $cpu, int $memoryMb): Service
    {
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->containerHosting()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
            'cpu_limit' => $cpu,
            'memory_limit_mb' => $memoryMb,
        ]);

        return $service->fresh();
    }
}
