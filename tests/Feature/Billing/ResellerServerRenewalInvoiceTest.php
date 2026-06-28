<?php

namespace Tests\Feature\Billing;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\InvoiceGenerationScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResellerServerRenewalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_owned_monthly_vps_is_due_ten_days_before_next_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-21'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $product = Product::factory()->create([
            'type' => 'vps',
            'wholesale_monthly_price' => 1500,
            'monthly_price' => 2500,
        ]);

        $service = Service::factory()->create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'next_due_date' => Carbon::parse('2026-03-31'),
            'custom_price' => 1800,
        ]);

        $schedule = app(InvoiceGenerationScheduleService::class);

        $this->assertTrue($schedule->isServiceDueForRenewalInvoice($service));
        $this->assertTrue(
            $schedule->servicesDueForRenewalInvoiceQuery()->whereKey($service->id)->exists()
        );

        Carbon::setTestNow();
    }

    public function test_renewal_pricing_uses_wholesale_for_reseller_owned_server_without_custom_price(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $product = Product::factory()->create([
            'type' => 'vps',
            'wholesale_monthly_price' => 1500,
            'monthly_price' => 2500,
            'resource_limits' => [
                'locations' => [[
                    'key' => 'nairobi',
                    'name' => 'Nairobi',
                    'monthly_price' => 2500,
                    'wholesale_monthly_price' => 1500,
                ]],
            ],
        ]);

        $service = Service::factory()->create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'service_meta' => ['location_key' => 'nairobi', 'ip_count' => 1],
        ]);

        $price = app(ServiceRenewalPricingService::class)->unitPrice($service);

        $this->assertSame(1500.0, $price);
    }

    public function test_generate_invoices_creates_renewal_for_reseller_vps_in_window(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-03-21'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $product = Product::factory()->create([
            'type' => 'vps',
            'wholesale_monthly_price' => 1500,
            'monthly_price' => 2500,
        ]);

        Service::factory()->create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'next_due_date' => Carbon::parse('2026-03-31'),
            'custom_price' => 1800,
        ]);

        $this->artisan('cron:generate-invoices')->assertSuccessful();

        $this->assertDatabaseHas('invoices', [
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 1800,
        ]);

        Carbon::setTestNow();
    }
}
