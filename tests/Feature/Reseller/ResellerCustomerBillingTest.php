<?php

namespace Tests\Feature\Reseller;

use App\Http\Controllers\Reseller\CartController;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerMarginEntry;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerAnalyticsService;
use App\Services\ResellerCustomerBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCustomerBillingTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_users' => 10,
            'price' => 500,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_reseller_can_create_and_record_payment_on_customer_invoice(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.customer-invoices.store'), [
                'customer_id' => $customer->id,
                'status' => 'unpaid',
                'due_date' => now()->addWeek()->toDateString(),
                'tax_rate' => 0,
                'items' => [
                    ['description' => 'Setup fee', 'quantity' => 1, 'unit_price' => 1000],
                ],
            ])
            ->assertRedirect();

        $invoice = Invoice::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(1000, (float) $invoice->total);

        $this->actingAs($reseller)
            ->post(route('reseller.customer-invoices.add-payment', $invoice), [
                'amount' => 400,
                'payment_method' => 'manual',
                'transaction_reference' => 'CASH-1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertEquals('unpaid', $invoice->status->value ?? $invoice->status);
        $this->assertEquals(600, $invoice->getAmountRemaining());

        $this->actingAs($reseller)
            ->post(route('reseller.customer-invoices.add-payment', $invoice), [
                'amount' => 600,
                'payment_method' => 'manual',
            ])
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status->value ?? $invoice->status);
        $this->assertEquals(0, $invoice->getAmountRemaining());
    }

    public function test_outstanding_balance_uses_amount_remaining_not_invoice_total(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 1000,
            'subtotal' => 1000,
            'tax' => 0,
        ]);

        Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 300,
            'currency' => 'KES',
            'payment_method' => 'manual',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $billing = app(ResellerCustomerBillingService::class);
        $this->assertEquals(700.0, $billing->customerOutstandingTotal($reseller));

        $metrics = app(ResellerAnalyticsService::class)->dashboardMetrics($reseller);
        $this->assertEquals(700.0, $metrics['outstandingBalance']);
    }

    public function test_reseller_dashboard_shows_billing_health_and_action_queue(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'overdue',
            'total' => 500,
            'due_date' => now()->subDay(),
        ]);

        $response = $this->actingAs($reseller)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Action queue');
        $response->assertSee('Service slots');
    }

    public function test_catalog_order_creates_customer_invoice(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $product = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Basic Hosting',
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'is_active' => true,
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.customer-orders.store'), [
                'customer_id' => $customer->id,
                'reseller_product_id' => $product->id,
                'billing_cycle' => 'monthly',
                'status' => 'unpaid',
            ])
            ->assertRedirect();

        $invoice = Invoice::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(500, (float) $invoice->total);
    }

    public function test_reports_csv_export_returns_csv(): void
    {
        $reseller = $this->reseller();
        User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $response = $this->actingAs($reseller)
            ->get(route('reseller.reports.export.customers'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type') ?? '');
    }

    public function test_domain_order_for_customer_uses_retail_price(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $extension = DomainExtension::create([
            'extension' => '.co',
            'description' => 'CO',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 800,
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 1500,
            'enabled' => true,
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.customer-orders.domain.store'), [
                'customer_id' => $customer->id,
                'domain' => 'brand',
                'extension_id' => $extension->id,
                'years' => 1,
            ])
            ->assertRedirect();

        $invoice = Invoice::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(1500, (float) $invoice->total);

        $order = ResellerDomainOrder::where('customer_id', $customer->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(1500, (float) $order->retail_amount);
        $this->assertEquals(800, (float) $order->wholesale_amount);
    }

    public function test_customer_cart_checkout_creates_single_customer_invoice(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $extension = DomainExtension::create([
            'extension' => '.io',
            'description' => 'IO',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 500,
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 900,
            'enabled' => true,
        ]);

        $cart = [
            'k1' => [
                'type' => 'domain',
                'domain' => 'one',
                'extension' => '.io',
                'years' => 1,
                'price' => 900,
                'retail_total' => 900,
            ],
            'k2' => [
                'type' => 'domain',
                'domain' => 'two',
                'extension' => '.io',
                'years' => 1,
                'price' => 900,
                'retail_total' => 900,
            ],
        ];

        $this->actingAs($reseller)
            ->post(route('reseller.cart.context'), ['customer_id' => $customer->id])
            ->assertRedirect();

        $this->actingAs($reseller)
            ->withSession([CartController::CART_KEY => $cart])
            ->post(route('reseller.checkout.process'), ['agree' => '1'])
            ->assertRedirect();

        $invoice = Invoice::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(1800, (float) $invoice->total);
        $this->assertCount(2, $invoice->items);
    }

    public function test_margin_ledger_records_on_customer_invoice_payment(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.customer-invoices.store'), [
                'customer_id' => $customer->id,
                'status' => 'unpaid',
                'due_date' => now()->addWeek()->toDateString(),
                'tax_rate' => 0,
                'items' => [
                    ['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 2000],
                ],
            ])
            ->assertRedirect();

        $invoice = Invoice::where('user_id', $customer->id)->latest()->first();

        $this->actingAs($reseller)
            ->post(route('reseller.customer-invoices.add-payment', $invoice), [
                'amount' => 2000,
                'payment_method' => 'manual',
            ])
            ->assertSessionHas('success');

        $this->assertGreaterThan(0, ResellerMarginEntry::where('reseller_id', $reseller->id)->count());
    }

    public function test_domain_can_be_provisioned_without_customer_invoice(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $extension = DomainExtension::create([
            'extension' => '.free',
            'description' => 'FREE',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 100,
            'enabled' => true,
        ]);

        $invoiceCountBefore = Invoice::where('user_id', $customer->id)->count();

        $this->actingAs($reseller)
            ->post(route('reseller.customer-orders.domain.store'), [
                'customer_id' => $customer->id,
                'domain' => 'gift',
                'extension_id' => $extension->id,
                'years' => 1,
                'bill_customer' => '0',
            ])
            ->assertRedirect(route('reseller.customers.show', $customer));

        $this->assertSame($invoiceCountBefore, Invoice::where('user_id', $customer->id)->count());

        $order = ResellerDomainOrder::where('customer_id', $customer->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(0, (float) $order->retail_amount);
        $this->assertNull($order->customer_invoice_id);
    }

    public function test_reports_page_shows_whitelabel_heading(): void
    {
        $reseller = $this->reseller();

        $this->actingAs($reseller)
            ->get(route('reseller.reports.index'))
            ->assertOk()
            ->assertSee('Whitelabel reports');
    }
}
