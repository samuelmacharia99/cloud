<?php

namespace Tests\Feature\Reseller;

use App\Http\Controllers\Reseller\CartController;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\DomainRenewalPushService;
use App\Services\Registrar\RegistrarFulfillmentService;
use App\Services\ResellerInvoicePaymentService;
use App\Services\ResellerWalletService;
use App\Support\ResellerCartContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerManagedDomainRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function createResellerWithPackage(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function seedRenewalPricing(): DomainExtension
    {
        $extension = DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 1200,
            'renewal_price' => 900,
            'enabled' => true,
        ]);

        return $extension;
    }

    public function test_customer_renewal_checkout_creates_dual_invoices(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $reseller = $this->createResellerWithPackage();
        $customer = User::factory()->create(['reseller_id' => $reseller->id, 'country' => 'KE']);
        $extension = $this->seedRenewalPricing();

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 2000,
            'renewal_retail_price' => 1750,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'client',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        ResellerCartContext::setCustomer($customer->id);

        $this->actingAs($reseller)
            ->postJson(route('reseller.domains.renew', $domain), ['years' => 1])
            ->assertOk();

        $cart = session(CartController::CART_KEY);

        $this->actingAs($reseller)
            ->withSession([CartController::CART_KEY => $cart])
            ->post(route('reseller.checkout.process'), ['agree' => '1'])
            ->assertRedirect();

        $renewalOrder = DomainRenewalOrder::where('domain_id', $domain->id)->first();
        $this->assertNotNull($renewalOrder);
        $this->assertTrue($renewalOrder->isResellerManaged());
        $this->assertSame(900.0, $renewalOrder->effectiveWholesaleAmount());
        $this->assertSame(1750.0, $renewalOrder->effectiveRetailAmount());
        $this->assertNotNull($renewalOrder->customer_invoice_id);
        $this->assertNotNull($renewalOrder->reseller_invoice_id);

        $customerInvoice = Invoice::find($renewalOrder->customer_invoice_id);
        $resellerInvoice = Invoice::find($renewalOrder->reseller_invoice_id);

        $this->assertSame($customer->id, $customerInvoice->user_id);
        $this->assertSame(1750.0, (float) $customerInvoice->total);
        $this->assertSame($reseller->id, $resellerInvoice->user_id);
        $this->assertSame(900.0, (float) $resellerInvoice->total);
    }

    public function test_customer_paid_renewal_debits_wallet_and_pushes_to_admin(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $this->mock(RegistrarFulfillmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillRenewal')->once();
        });

        $reseller = $this->createResellerWithPackage();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 5000]);

        $customer = User::factory()->create(['reseller_id' => $reseller->id, 'country' => 'KE']);
        $extension = $this->seedRenewalPricing();

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 2000,
            'renewal_retail_price' => 1750,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'wallet',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        ResellerCartContext::setCustomer($customer->id);

        $this->actingAs($reseller)
            ->postJson(route('reseller.domains.renew', $domain), ['years' => 1])
            ->assertOk();

        $cart = session(CartController::CART_KEY);

        $this->actingAs($reseller)
            ->withSession([CartController::CART_KEY => $cart])
            ->post(route('reseller.checkout.process'), ['agree' => '1']);

        $renewalOrder = DomainRenewalOrder::where('domain_id', $domain->id)->firstOrFail();
        $customerInvoice = Invoice::findOrFail($renewalOrder->customer_invoice_id);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $customerInvoice->id,
            'amount' => $customerInvoice->total,
            'currency' => 'KES',
            'payment_method' => 'manual',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $renewalOrder->refresh();
        $reseller->refresh();

        $this->assertSame('pushed', $renewalOrder->status);
        $this->assertNotNull($renewalOrder->wallet_transaction_id);
        $this->assertNotNull($renewalOrder->admin_order_id);
        $this->assertSame(900.0, (float) $renewalOrder->walletTransaction->amount);
        $this->assertLessThan(5000.0, (float) $reseller->fresh()->wallet->balance);
    }

    public function test_customer_paid_without_wallet_queues_until_reseller_pays_wholesale(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $this->mock(RegistrarFulfillmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillRenewal')->once();
        });

        $reseller = $this->createResellerWithPackage();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 0]);

        $customer = User::factory()->create(['reseller_id' => $reseller->id, 'country' => 'KE']);
        $extension = $this->seedRenewalPricing();

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 2000,
            'renewal_retail_price' => 1750,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'queued',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        ResellerCartContext::setCustomer($customer->id);

        $this->actingAs($reseller)
            ->postJson(route('reseller.domains.renew', $domain), ['years' => 1])
            ->assertOk();

        $cart = session(CartController::CART_KEY);

        $this->actingAs($reseller)
            ->withSession([CartController::CART_KEY => $cart])
            ->post(route('reseller.checkout.process'), ['agree' => '1']);

        $renewalOrder = DomainRenewalOrder::where('domain_id', $domain->id)->firstOrFail();
        $customerInvoice = Invoice::findOrFail($renewalOrder->customer_invoice_id);
        $resellerInvoice = Invoice::findOrFail($renewalOrder->reseller_invoice_id);

        app(InvoiceSettlementService::class)->settleFromPayment(Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $customerInvoice->id,
            'amount' => $customerInvoice->total,
            'currency' => 'KES',
            'payment_method' => 'manual',
            'status' => 'completed',
            'paid_at' => now(),
        ]));

        $renewalOrder->refresh();
        $this->assertSame('queued', $renewalOrder->status);
        $this->assertNull($renewalOrder->admin_order_id);

        $wholesalePayment = Payment::create([
            'user_id' => $reseller->id,
            'invoice_id' => $resellerInvoice->id,
            'amount' => $resellerInvoice->total,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        app(ResellerInvoicePaymentService::class)->completeInvoiceIfFullyPaid($resellerInvoice, $wholesalePayment);
        app(DomainRenewalPushService::class)->handlePaidInvoice($resellerInvoice->fresh());

        $renewalOrder->refresh();
        $this->assertSame('pushed', $renewalOrder->status);
        $this->assertNotNull($renewalOrder->admin_order_id);
    }
}
