<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Enums\RegistrarDriver;
use App\Models\Currency;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Node;
use App\Models\Payment;
use App\Models\Registrar;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AdminBookkeepingService;
use App\Services\ResellerWalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookkeepingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
        $this->seedUsdRate(0.0077);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cash_in_uses_kes_and_paid_at_and_excludes_managed_customer_retail(): void
    {
        $platformCustomer = User::factory()->customer()->create(['reseller_id' => null]);
        $reseller = User::factory()->reseller()->create();
        $managed = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->createCompletedPayment($platformCustomer, 1000, '2026-08-10 09:00:00');
        $this->createCompletedPayment($managed, 500, '2026-08-10 09:00:00');
        $this->createCompletedPayment($platformCustomer, 2000, '2026-07-20 09:00:00');

        $usdInvoice = Invoice::factory()->create([
            'user_id' => $platformCustomer->id,
            'status' => 'paid',
            'currency' => 'USD',
            'total' => 7.70,
        ]);
        Payment::factory()->create([
            'user_id' => $platformCustomer->id,
            'invoice_id' => $usdInvoice->id,
            'amount' => 7.70,
            'currency' => 'USD',
            'amount_base_kes' => 1000,
            'status' => PaymentStatus::Completed,
            'paid_at' => Carbon::parse('2026-08-11 10:00:00'),
            'payment_method' => 'stripe',
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);

        $this->assertSame(2000.0, $report['cashIn']);
        $this->assertSame(2026, $report['year']);
        $this->assertSame(8, $report['month']);
    }

    public function test_node_provider_cost_and_hosting_revenue_are_attributed_to_the_node(): void
    {
        $node = Node::factory()->create([
            'name' => 'Nairobi DA',
            'type' => 'directadmin',
            'monthly_cost_usd' => 100,
        ]);
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'total' => 20000,
            'subtotal' => 20000,
            'tax' => 0,
            'currency' => 'KES',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'node_id' => $node->id,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $service->product_id,
            'description' => 'Shared hosting',
            'quantity' => 1,
            'unit_price' => 20000,
            'amount' => 20000,
        ]);
        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 20000,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => Carbon::parse('2026-08-08 08:00:00'),
            'payment_method' => 'mpesa',
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);
        $row = collect($report['nodes'])->firstWhere('id', $node->id);
        $monthlyKes = $node->fresh()->monthlyCostKes();

        $this->assertNotNull($row);
        $this->assertNotNull($monthlyKes);
        $this->assertEqualsWithDelta($monthlyKes, $row['cost_kes'], 0.05);
        $this->assertEqualsWithDelta(20000.0, $row['hosting_revenue'], 0.01);
        $this->assertEqualsWithDelta(20000.0 - $monthlyKes, $row['profit'], 0.05);
        $this->assertEqualsWithDelta($monthlyKes, $report['costs']['nodes'], 0.05);
        $this->assertSame(0, $report['costs']['nodes_untracked']);
        $this->assertSame(0, $report['costs']['nodes_missing_rate']);
        $this->assertSame('ok', $row['cost_status']);
    }

    public function test_saved_usd_spend_is_not_counted_as_missing_when_kes_rate_is_unavailable(): void
    {
        Currency::query()->where('code', 'USD')->delete();

        $tracked = Node::factory()->create([
            'name' => 'Paid box',
            'monthly_cost_usd' => 120,
        ]);
        Node::factory()->create([
            'name' => 'Empty box',
            'monthly_cost_usd' => null,
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);
        $paid = collect($report['nodes'])->firstWhere('id', $tracked->id);

        $this->assertSame(120.0, $paid['monthly_cost_usd']);
        $this->assertNull($paid['cost_kes']);
        $this->assertSame('missing_rate', $paid['cost_status']);
        $this->assertSame(1, $report['costs']['nodes_untracked']);
        $this->assertSame(1, $report['costs']['nodes_missing_rate']);
        $this->assertSame(0.0, $report['costs']['nodes']);
    }

    public function test_unassigned_hosting_with_zero_cost_is_not_counted_as_an_untracked_node(): void
    {
        Node::factory()->create(['monthly_cost_usd' => 100]);
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'total' => 3000,
            'subtotal' => 3000,
            'tax' => 0,
            'currency' => 'KES',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'node_id' => null,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $service->product_id,
            'description' => 'Orphan hosting',
            'quantity' => 1,
            'unit_price' => 3000,
            'amount' => 3000,
        ]);
        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 3000,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => Carbon::parse('2026-08-08 08:00:00'),
            'payment_method' => 'mpesa',
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);
        $unassigned = collect($report['nodes'])->firstWhere('id', 0);

        $this->assertNotNull($unassigned);
        $this->assertSame(0.0, $unassigned['cost_kes']);
        $this->assertSame(0, $report['costs']['nodes_untracked']);
    }

    public function test_reseller_package_fee_is_counted_on_the_assigned_node(): void
    {
        $node = Node::factory()->directAdmin()->create([
            'name' => 'Reseller box',
            'monthly_cost_usd' => 50,
        ]);
        $reseller = User::factory()->reseller()->create([
            'reseller_node_id' => $node->id,
        ]);
        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'paid',
            'total' => 4000,
            'subtotal' => 4000,
            'tax' => 0,
            'currency' => 'KES',
        ]);
        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'amount' => 4000,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => Carbon::parse('2026-08-05 11:00:00'),
            'payment_method' => 'mpesa',
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);
        $row = collect($report['nodes'])->firstWhere('id', $node->id);

        $this->assertEqualsWithDelta(4000.0, $report['income']['reseller_subscription'], 0.01);
        $this->assertEqualsWithDelta(4000.0, $row['subscription_revenue'], 0.01);
    }

    public function test_wallet_paid_package_fee_is_allocated_to_the_node_without_increasing_cash_in(): void
    {
        $node = Node::factory()->directAdmin()->create([
            'name' => 'Wallet node',
            'monthly_cost_usd' => null,
        ]);
        $reseller = User::factory()->reseller()->create([
            'reseller_node_id' => $node->id,
        ]);
        $wallet = app(ResellerWalletService::class)->getOrCreate($reseller);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'subscription_debit',
            'amount' => 2500,
            'balance_before' => 5000,
            'balance_after' => 2500,
            'description' => 'Package auto-pay',
            'status' => 'completed',
            'created_at' => Carbon::parse('2026-08-12 10:00:00'),
            'updated_at' => Carbon::parse('2026-08-12 10:00:00'),
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);
        $row = collect($report['nodes'])->firstWhere('id', $node->id);

        $this->assertSame(0.0, $report['cashIn']);
        $this->assertEqualsWithDelta(2500.0, $row['subscription_revenue'], 0.01);
        $this->assertEqualsWithDelta(2500.0, $row['revenue'], 0.01);
    }

    public function test_cosmotown_domain_profit_is_wholesale_minus_registrar_cost(): void
    {
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $extension = $this->createCosmotownComExtension();

        ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_name' => 'profitcheck',
            'extension' => $extension->extension,
            'years' => 1,
            'wholesale_amount' => 1500,
            'retail_amount' => 1500,
            'status' => 'completed',
            'completed_at' => Carbon::parse('2026-08-09 14:00:00'),
        ]);

        DomainExtension::create([
            'extension' => '.net',
            'enabled' => true,
            'registrar' => 'internal',
            'registrar_register_cost_kes' => 100,
        ]);
        ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_name' => 'notcosmo',
            'extension' => '.net',
            'years' => 1,
            'wholesale_amount' => 900,
            'retail_amount' => 900,
            'status' => 'completed',
            'completed_at' => Carbon::parse('2026-08-09 14:00:00'),
        ]);

        $report = app(AdminBookkeepingService::class)->build(2026, 8);

        $this->assertSame(1500.0, $report['domains']['collected']);
        $this->assertSame(759.0, $report['domains']['cost']);
        $this->assertSame(741.0, $report['domains']['profit']);
        $this->assertSame(1, $report['domains']['count']);
        $this->assertSame(759.0, $report['costs']['domains']);
    }

    public function test_full_year_filter_includes_each_month_and_does_not_charge_future_provider_months(): void
    {
        $node = Node::factory()->create(['monthly_cost_usd' => 100]);
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $this->createCompletedPayment($customer, 1000, '2026-01-15 09:00:00');
        $this->createCompletedPayment($customer, 500, '2026-08-02 09:00:00');

        $report = app(AdminBookkeepingService::class)->build(2026, null);

        $this->assertSame(1500.0, $report['cashIn']);
        $this->assertSame(8, $report['costMonths']);
        $this->assertEqualsWithDelta((float) $node->fresh()->monthlyCostKes() * 8, $report['costs']['nodes'], 0.2);
        $this->assertSame('month', $report['chart']['granularity']);
        $this->assertCount(12, $report['chart']['labels']);
    }

    private function createCompletedPayment(User $user, float $amount, string $paidAt): Payment
    {
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total' => $amount,
            'subtotal' => $amount,
            'tax' => 0,
            'currency' => 'KES',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Service',
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'product_id' => null,
        ]);

        return Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => Carbon::parse($paidAt),
            'payment_method' => 'mpesa',
        ]);
    }

    private function createCosmotownComExtension(): DomainExtension
    {
        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-bookkeeping',
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'config' => ['api_token' => 'test'],
        ]);

        return DomainExtension::create([
            'extension' => '.com',
            'enabled' => true,
            'registrar' => 'Cosmotown',
            'registrar_id' => $registrar->id,
            'registrar_register_cost_usd' => 7.59,
            'registrar_register_cost_kes' => 759,
            'registrar_renew_cost_kes' => 1108,
            'registrar_transfer_cost_kes' => 1108,
        ]);
    }

    private function seedUsdRate(float $usdPerKes): void
    {
        Currency::query()->updateOrCreate(
            ['code' => 'KES'],
            ['name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'exchange_rate' => 1.0, 'is_active' => true, 'order' => 1]
        );
        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'United States Dollar', 'symbol' => '$', 'exchange_rate' => $usdPerKes, 'is_active' => true, 'order' => 20]
        );
    }
}
