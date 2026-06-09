<?php

namespace Tests\Feature\Reseller;

use App\Models\Invoice;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\ResellerDiskUsageService;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDiskUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_daily_disk_snapshot_for_reseller(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Pro',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_services' => 100,
            'disk_pool_gb' => 100,
            'max_users' => 20,
            'price' => 1000,
            'active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);

        $snapshot = app(ResellerDiskUsageService::class)->recordDailySnapshot($reseller);

        $this->assertDatabaseHas('reseller_disk_usage_snapshots', [
            'reseller_id' => $reseller->id,
        ]);

        $this->assertSame(100, app(ResellerDiskUsageService::class)->diskPoolGb($reseller));
        $this->assertNotNull($snapshot->total_used_gb);
    }

    public function test_renewal_subscription_invoice_includes_disk_usage_line_items(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Growth',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_services' => 50,
            'disk_pool_gb' => 50,
            'disk_overage_rate' => 25,
            'max_users' => 10,
            'price' => 2000,
            'active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addDays(5),
        ]);

        ResellerDiskUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'period_date' => now()->subDays(10)->toDateString(),
            'directadmin_used_gb' => 30,
            'container_used_gb' => 25,
            'total_used_gb' => 55,
            'recorded_at' => now()->subDays(10),
        ]);

        ResellerDiskUsageSnapshot::create([
            'reseller_id' => $reseller->id,
            'period_date' => now()->subDay()->toDateString(),
            'directadmin_used_gb' => 35,
            'container_used_gb' => 30,
            'total_used_gb' => 65,
            'recorded_at' => now()->subDay(),
        ]);

        $invoice = app(ResellerPackageSubscriptionService::class)->createSubscriptionInvoice($reseller, $package, renewal: true);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertTrue(
            $invoice->items()->where('product_type', 'reseller_disk_usage')->exists()
        );

        $overageItem = $invoice->items()->where('product_type', 'reseller_disk_overage')->first();
        $this->assertNotNull($overageItem);
        $this->assertGreaterThan(0, (float) $overageItem->amount);
    }
}
