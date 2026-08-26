<?php

namespace Tests\Feature\Admin;

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
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsTest extends TestCase
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.reports.index'))
            ->assertRedirect();
    }

    public function test_customer_cannot_view_bookkeeping(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('admin.reports.index'))
            ->assertForbidden();
    }

    public function test_admin_sees_bookkeeping_for_the_current_month_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $this->seedPlatformPayment($customer, 2500, '2026-08-04 10:00:00');
        $this->seedPlatformPayment($customer, 800, '2026-07-04 10:00:00');

        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Bookkeeping')
            ->assertSee('August 2026')
            ->assertSee('2,500.00')
            ->assertSee('Cash in vs spend')
            ->assertSee('Profit by node')
            ->assertSee('Cosmotown domain profit')
            ->assertSee('Coin trail');
    }

    public function test_year_and_month_filters_change_the_period(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $this->seedPlatformPayment($customer, 1200, '2026-01-20 10:00:00');
        $this->seedPlatformPayment($customer, 3400, '2026-08-02 10:00:00');

        $this->actingAs($admin)
            ->get(route('admin.reports.index', ['year' => 2026, 'month' => 'all']))
            ->assertOk()
            ->assertSee('4,600.00');

        $this->actingAs($admin)
            ->get(route('admin.reports.index', ['year' => 2026, 'month' => 1]))
            ->assertOk()
            ->assertSee('January 2026')
            ->assertSee('1,200.00')
            ->assertDontSee('3,400.00');
    }

    public function test_invalid_month_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.reports.index'))
            ->get(route('admin.reports.index', ['year' => 2026, 'month' => 13]))
            ->assertRedirect(route('admin.reports.index'))
            ->assertSessionHasErrors('month');
    }

    public function test_page_shows_node_and_domain_money_and_the_payment_trail(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['name' => 'Amina Otieno', 'reseller_id' => null]);
        Node::factory()->create([
            'name' => 'Mombasa App Host',
            'monthly_cost_usd' => 100,
        ]);

        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-reports',
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'config' => ['api_token' => 'test'],
        ]);
        DomainExtension::create([
            'extension' => '.com',
            'enabled' => true,
            'registrar' => 'Cosmotown',
            'registrar_id' => $registrar->id,
            'registrar_register_cost_kes' => 759,
        ]);
        ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_name' => 'ledgertrace',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 1650,
            'retail_amount' => 1650,
            'status' => 'completed',
            'completed_at' => Carbon::parse('2026-08-06 16:00:00'),
        ]);

        $this->seedPlatformPayment($customer, 5000, '2026-08-06 09:30:00');

        $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Mombasa App Host')
            ->assertSee('ledgertrace.com')
            ->assertSee('Amina Otieno')
            ->assertSee('Coin trail')
            ->assertSee('5,000.00')
            ->assertSee('1,650.00')
            ->assertSee('759.00');
    }

    private function seedPlatformPayment(User $user, float $amount, string $paidAt): void
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
            'description' => 'Hosting',
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
        ]);
        Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => 'KES',
            'status' => PaymentStatus::Completed,
            'paid_at' => Carbon::parse($paidAt),
            'payment_method' => 'mpesa',
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
