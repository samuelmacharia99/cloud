<?php

namespace Tests\Feature\Customer;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\CreditService;
use App\Services\ResellerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private function domainWithPricing(
        User $owner,
        bool $autoRenew = false,
        string $name = 'autorenew',
        $expiresAt = null,
    ): Domain {
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
