<?php

namespace Tests\Feature\Customer;

use App\Mail\DomainAutoRenewUnpaidMail;
use App\Mail\InvoiceGeneratedMail;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\CreditService;
use App\Services\CustomerCreditTopupService;
use App\Services\DomainAutoRenewService;
use App\Services\ResellerWalletService;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DomainAutoRenewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tax_enabled', 'false');
        Setting::setValue('domain_renewal_advance_days', '30');
        Http::fake();
    }

    public function test_customer_cannot_enable_auto_renew_without_enough_credits(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domainWithPricing($customer, autoRenew: false);
        CreditService::createManualCredit($customer, 100, 'Too little');

        $this->actingAs($customer)
            ->from(route('customer.domains.index'))
            ->post(route('customer.domains.auto-renew', $domain), ['auto_renew' => 1])
            ->assertRedirect(route('customer.domains.index'))
            ->assertSessionHas('error');

        $this->assertFalse($domain->fresh()->auto_renew);
        $this->assertSame(100.0, CreditService::getAvailableBalance($customer->fresh()));
    }

    public function test_customer_can_enable_auto_renew_when_credits_cover_renewal(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domainWithPricing($customer, autoRenew: false);
        CreditService::createManualCredit($customer, 1500, 'Renewal cover');

        $this->actingAs($customer)
            ->from(route('customer.domains.index'))
            ->post(route('customer.domains.auto-renew', $domain), ['auto_renew' => 1])
            ->assertRedirect(route('customer.domains.index'))
            ->assertSessionHas('success');

        $this->assertTrue($domain->fresh()->auto_renew);
        $this->assertSame(1500.0, CreditService::getAvailableBalance($customer->fresh()));
    }

    public function test_second_auto_renew_domain_requires_credits_for_both(): void
    {
        $customer = User::factory()->customer()->create();
        $first = $this->domainWithPricing($customer, autoRenew: true, name: 'first');
        $second = $this->domainWithPricing($customer, autoRenew: false, name: 'second');
        CreditService::createManualCredit($customer, 1500, 'Covers one renewal only');

        $this->actingAs($customer)
            ->from(route('customer.domains.index'))
            ->post(route('customer.domains.auto-renew', $second), ['auto_renew' => 1])
            ->assertSessionHas('error');

        $this->assertTrue($first->fresh()->auto_renew);
        $this->assertFalse($second->fresh()->auto_renew);
    }

    public function test_generate_invoices_auto_pays_auto_renew_domain_from_credits(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domainWithPricing($customer, autoRenew: true, expiresAt: now()->addDays(15));
        CreditService::createManualCredit($customer, 2000, 'Auto-renew');

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        $order = DomainRenewalOrder::query()->where('domain_id', $domain->id)->first();
        $this->assertNotNull($order);
        $invoice = $order->customerInvoice ?? $order->invoice;
        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->fresh()->isPaid());
        $this->assertLessThan(2000.0, CreditService::getAvailableBalance($customer->fresh()));
        $this->assertContains($order->fresh()->status, ['paid', 'pushed', 'failed', 'queued']);
    }

    public function test_generate_invoices_leaves_auto_renew_unpaid_without_credits(): void
    {
        $customer = User::factory()->customer()->create();
        $this->domainWithPricing($customer, autoRenew: true, expiresAt: now()->addDays(15));

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        $invoice = Invoice::query()->where('user_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertFalse($invoice->isPaid());
        $this->assertSame(0.0, CreditService::getAvailableBalance($customer->fresh()));
    }

    public function test_reseller_auto_renew_debits_wallet_when_invoice_is_generated(): void
    {
        $reseller = User::factory()->reseller()->create();
        $domain = $this->domainWithPricing($reseller, autoRenew: true, expiresAt: now()->addDays(15));
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 5000]);

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        $order = DomainRenewalOrder::query()->where('domain_id', $domain->id)->first();
        $this->assertNotNull($order);
        $invoice = $order->customerInvoice ?? $order->invoice;
        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->fresh()->isPaid());
        $this->assertLessThan(5000.0, (float) $reseller->fresh()->wallet->balance);
    }

    public function test_queued_renewal_cron_retries_unpaid_auto_renew_after_credits_added(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domainWithPricing($customer, autoRenew: true, expiresAt: now()->addDays(15));

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        $invoice = Invoice::query()->where('user_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertFalse($invoice->isPaid());

        CreditService::createManualCredit($customer, 2000, 'Top-up after invoice');

        $this->artisan('cron:process-queued-domain-renewals')->assertSuccessful();

        $this->assertTrue($invoice->fresh()->isPaid());
    }

    public function test_credit_topup_pays_open_auto_renew_invoice(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domainWithPricing($customer, autoRenew: true, expiresAt: now()->addDays(15));

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        $invoice = Invoice::query()->where('user_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertFalse($invoice->isPaid());

        $topupInvoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'CREDIT-AR001',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 2000,
            'tax' => 0,
            'total' => 2000,
        ]);
        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $topupInvoice->id,
            'amount' => 2000,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'credit_topup',
            'transaction_reference' => 'TEST-AR-TOPUP',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        app(CustomerCreditTopupService::class)->processTopupPayment($payment);

        $this->assertTrue($invoice->fresh()->isPaid());
    }

    public function test_wallet_topup_pays_open_reseller_auto_renew_invoice(): void
    {
        $reseller = User::factory()->reseller()->create();
        $this->domainWithPricing($reseller, autoRenew: true, expiresAt: now()->addDays(15));

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        $invoice = Invoice::query()->where('user_id', $reseller->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertFalse($invoice->isPaid());

        $topupInvoice = Invoice::create([
            'user_id' => $reseller->id,
            'invoice_number' => 'WALLET-AR001',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 5000,
            'tax' => 0,
            'total' => 5000,
        ]);
        $payment = Payment::create([
            'user_id' => $reseller->id,
            'invoice_id' => $topupInvoice->id,
            'amount' => 5000,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'wallet_topup',
            'transaction_reference' => 'TEST-AR-WALLET',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        app(ResellerWalletService::class)->processTopupPayment($payment);

        $this->assertTrue($invoice->fresh()->isPaid());
    }

    public function test_unpaid_auto_renew_invoice_sends_dedicated_notice(): void
    {
        Mail::fake();
        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_from_address', 'noreply@example.com');
        Setting::setValue('notify_invoice_generated', 'true');

        $customer = User::factory()->customer()->create(['email' => 'autorenew@example.com']);
        $this->domainWithPricing($customer, autoRenew: true, expiresAt: now()->addDays(15));

        $this->artisan('cron:generate-domain-invoices')->assertSuccessful();

        Mail::assertSent(DomainAutoRenewUnpaidMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
        Mail::assertNotSent(InvoiceGeneratedMail::class);
    }

    public function test_checkout_enables_auto_renew_when_credits_cover_renewal(): void
    {
        $customer = User::factory()->customer()->create();
        $this->ensureRetailPricing();
        CreditService::createManualCredit($customer, 2000, 'Cover renewal');

        $this->actingAs($customer);
        SessionCart::putPortal([
            'domain_checkout_com' => [
                'type' => 'domain',
                'domain' => 'checkoutcover',
                'extension' => '.com',
                'years' => 1,
                'auto_renew' => true,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'ns1.talksasa.com',
                    'ns2' => 'ns2.talksasa.com',
                ],
            ],
        ]);

        $this->post(route('customer.checkout.process'), [
            'agree_terms' => '1',
        ])->assertRedirect();

        $domain = Domain::query()->where('user_id', $customer->id)->where('name', 'checkoutcover')->first();
        $this->assertNotNull($domain);
        $this->assertTrue($domain->auto_renew);
    }

    public function test_checkout_leaves_auto_renew_off_when_credits_are_short(): void
    {
        $customer = User::factory()->customer()->create();
        $this->ensureRetailPricing();

        $this->actingAs($customer);
        SessionCart::putPortal([
            'domain_checkout_com' => [
                'type' => 'domain',
                'domain' => 'checkoutshort',
                'extension' => '.com',
                'years' => 1,
                'auto_renew' => true,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'ns1.talksasa.com',
                    'ns2' => 'ns2.talksasa.com',
                ],
            ],
        ]);

        $this->post(route('customer.checkout.process'), [
            'agree_terms' => '1',
        ])->assertRedirect();

        $domain = Domain::query()->where('user_id', $customer->id)->where('name', 'checkoutshort')->first();
        $this->assertNotNull($domain);
        $this->assertFalse($domain->auto_renew);
    }

    public function test_cart_auto_renew_toggle_is_stored(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer);
        SessionCart::putPortal([
            'domain_example_com' => [
                'type' => 'domain',
                'domain' => 'example',
                'extension' => '.com',
                'years' => 1,
                'auto_renew' => false,
            ],
        ]);

        $this->put(route('customer.cart.auto-renew', 'domain_example_com'), [
            'auto_renew' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue(SessionCart::portal()['domain_example_com']['auto_renew']);
    }

    public function test_should_enable_at_purchase_requires_cover_for_existing_auto_renew_domains(): void
    {
        $customer = User::factory()->customer()->create();
        $this->domainWithPricing($customer, autoRenew: true, name: 'alreadyon');
        $extension = DomainExtension::query()->where('extension', '.com')->firstOrFail();
        CreditService::createManualCredit($customer, 1500, 'Covers one renewal only');

        $canEnable = app(DomainAutoRenewService::class)->shouldEnableAtPurchase(
            $customer,
            $extension,
            true,
        );

        $this->assertFalse($canEnable);
    }

    private function ensureRetailPricing(): DomainExtension
    {
        $extension = DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );

        DomainPricing::query()->firstOrCreate(
            [
                'domain_extension_id' => $extension->id,
                'period_years' => 1,
                'tier' => 'wholesale',
            ],
            ['price' => 1000, 'renewal_price' => 900, 'enabled' => true]
        );
        DomainPricing::query()->firstOrCreate(
            [
                'domain_extension_id' => $extension->id,
                'period_years' => 1,
                'tier' => 'retail',
            ],
            ['price' => 1400, 'renewal_price' => 1200, 'enabled' => true]
        );

        return $extension;
    }

    private function domainWithPricing(
        User $owner,
        bool $autoRenew = false,
        string $name = 'autorenew',
        $expiresAt = null,
    ): Domain {
        $this->ensureRetailPricing();

        return Domain::create([
            'user_id' => $owner->id,
            'reseller_id' => $owner->is_reseller ? $owner->id : $owner->reseller_id,
            'name' => $name.'-'.$owner->id.'-'.uniqid(),
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => $expiresAt ?? now()->addYear(),
            'auto_renew' => $autoRenew,
        ]);
    }
}
