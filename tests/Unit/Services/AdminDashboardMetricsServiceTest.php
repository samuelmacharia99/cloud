<?php

namespace Tests\Unit\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminDashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->updateOrCreate(
            ['code' => 'KES'],
            [
                'name' => 'Kenyan Shilling',
                'symbol' => 'KES',
                'exchange_rate' => 1,
                'is_active' => true,
                'rate_updated_at' => now(),
            ]
        );
        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'symbol' => '$',
                'exchange_rate' => 0.0077,
                'is_active' => true,
                'rate_updated_at' => now(),
            ]
        );
    }

    private function platformCustomer(array $attributes = []): User
    {
        return User::factory()->customer()->create(array_merge([
            'preferred_currency' => 'KES',
            'country' => 'KE',
        ], $attributes));
    }

    public function test_platform_revenue_converts_non_kes_payments_to_base_kes(): void
    {
        $customer = $this->platformCustomer();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => InvoiceStatus::Paid,
            'total' => 100,
            'total_base_kes' => 100,
            'currency' => 'KES',
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        // 7.7 USD at rate 0.0077 => 1000 KES
        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 7.7,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $reseller = User::factory()->reseller()->create(['preferred_currency' => 'KES']);
        $managed = $this->platformCustomer(['reseller_id' => $reseller->id]);
        $retailInvoice = Invoice::factory()->create(['user_id' => $managed->id, 'currency' => 'KES', 'total_base_kes' => 100]);
        Payment::factory()->create([
            'user_id' => $managed->id,
            'invoice_id' => $retailInvoice->id,
            'amount' => 9999,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $metrics = app(AdminDashboardMetricsService::class)->metrics();

        $this->assertEqualsWithDelta(2000.0, $metrics['totalRevenue'], 0.5);
        $this->assertEqualsWithDelta(2000.0, $metrics['collectedToday'], 0.5);
    }

    public function test_platform_revenue_uses_payment_time_exchange_rate_snapshot(): void
    {
        $customer = $this->platformCustomer(['preferred_currency' => 'USD']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => InvoiceStatus::Paid,
            'currency' => 'USD',
            'exchange_rate' => 0.0077,
            'total' => 7.7,
            'total_base_kes' => 1000,
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 7.7,
            'currency' => 'USD',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        Currency::query()->where('code', 'USD')->update(['exchange_rate' => 0.01]);

        $metrics = app(AdminDashboardMetricsService::class)->metrics();

        $this->assertEqualsWithDelta(1000.0, $metrics['totalRevenue'], 0.01);
    }

    public function test_unpaid_ar_uses_remaining_and_excludes_reseller_retail(): void
    {
        $customer = $this->platformCustomer();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 1000,
            'total_base_kes' => 1000,
            'currency' => 'KES',
            'wallet_amount_applied' => 0,
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 400,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => now()->subDay(),
        ]);

        $reseller = User::factory()->reseller()->create(['preferred_currency' => 'KES']);
        $managed = $this->platformCustomer(['reseller_id' => $reseller->id]);
        Invoice::factory()->create([
            'user_id' => $managed->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 5000,
            'total_base_kes' => 5000,
            'currency' => 'KES',
        ]);

        $metrics = app(AdminDashboardMetricsService::class)->metrics();

        $this->assertEqualsWithDelta(600.0, $metrics['unpaidInvoiceTotal'], 0.01);
    }

    public function test_collected_today_uses_paid_at_not_created_at(): void
    {
        $customer = $this->platformCustomer();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => InvoiceStatus::Paid,
            'currency' => 'KES',
            'total_base_kes' => 100,
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 250,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'created_at' => now()->subDays(2),
            'paid_at' => now(),
        ]);

        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'created_at' => now(),
            'paid_at' => now()->subDays(3),
        ]);

        $metrics = app(AdminDashboardMetricsService::class)->metrics();

        $this->assertEqualsWithDelta(250.0, $metrics['collectedToday'], 0.01);
    }

    public function test_collected_today_includes_reseller_domain_payments(): void
    {
        $reseller = User::factory()->reseller()->create(['preferred_currency' => 'KES']);
        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => InvoiceStatus::Paid,
            'currency' => 'KES',
            'total' => 1800,
            'total_base_kes' => 1800,
            'notes' => 'Domain renewal order',
        ]);

        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'amount' => 1800,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $metrics = app(AdminDashboardMetricsService::class)->metrics();

        $this->assertEqualsWithDelta(1800.0, $metrics['collectedToday'], 0.01);
        $this->assertEqualsWithDelta(1800.0, $metrics['totalRevenue'], 0.01);
    }

    public function test_top_products_count_only_active_services(): void
    {
        $product = Product::factory()->create(['monthly_price' => 500]);
        $customer = $this->platformCustomer();

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
        ]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Terminated,
        ]);

        $metrics = app(AdminDashboardMetricsService::class)->metrics();
        $top = $metrics['topProducts']->firstWhere('id', $product->id);

        $this->assertNotNull($top);
        $this->assertSame(1, (int) $top->services_count);
    }
}
