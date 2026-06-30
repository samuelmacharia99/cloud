<?php

namespace Tests\Feature\Reseller;

use App\Http\Controllers\Reseller\CartController;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\DomainRenewalOrder;
use App\Models\Payment;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\DomainRenewalService;
use App\Services\ResellerInvoicePaymentService;
use App\Support\ResellerCartContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRenewalCartTest extends TestCase
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

    public function test_reseller_can_add_domain_renewal_to_cart_and_checkout_creates_invoice(): void
    {
        $reseller = $this->createResellerWithPackage();

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
            'renewal_price' => 1000,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $renewResponse = $this->actingAs($reseller)
            ->postJson(route('reseller.domains.renew', $domain), ['years' => 1]);

        $renewResponse->assertOk()
            ->assertJson(['success' => true]);

        $cart = session(CartController::CART_KEY);
        $this->assertCount(1, $cart);
        $item = array_values($cart)[0];
        $this->assertSame('domain_renewal', $item['type']);
        $this->assertSame($domain->id, $item['domain_id']);

        $this->actingAs($reseller)
            ->withSession([CartController::CART_KEY => $cart])
            ->post(route('reseller.checkout.process'), ['agree' => '1'])
            ->assertRedirect();

        $renewalOrder = DomainRenewalOrder::where('domain_id', $domain->id)->first();
        $this->assertNotNull($renewalOrder);
        $this->assertSame('invoiced', $renewalOrder->status);
        $this->assertSame(1000.0, (float) $renewalOrder->amount);
        $this->assertNotNull($renewalOrder->invoice_id);
    }

    public function test_customer_cart_mode_uses_retail_renewal_rate_in_cart(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $extension = DomainExtension::create([
            'extension' => '.co.ke',
            'description' => 'CO.KE',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'tier' => 'wholesale',
            'price' => 1200,
            'renewal_price' => 900,
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'retail_price' => 2000,
            'renewal_retail_price' => 1750,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'client',
            'extension' => '.co.ke',
            'status' => 'active',
            'type' => 'registration',
        ]);

        ResellerCartContext::setCustomer($customer->id);

        $renewResponse = $this->actingAs($reseller)
            ->postJson(route('reseller.domains.renew', $domain), ['years' => 2]);

        $renewResponse->assertOk()
            ->assertJson(['success' => true]);

        $cart = session(CartController::CART_KEY);
        $item = array_values($cart)[0];

        $this->assertSame(1750.0, (float) $item['price']);
        $this->assertSame(1750.0, (float) $item['retail_total']);
        $this->assertSame(900.0, (float) $item['wholesale_total']);
        $this->assertSame($customer->id, $item['billing_customer_id']);
    }

    public function test_customer_owned_domain_renewal_uses_retail_rate_without_cart_mode(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $extension = DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 1350,
            'renewal_price' => 1400,
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 2000,
            'renewal_retail_price' => 2200,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'devkisteelmaishamabati',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        ResellerCartContext::setSelf();

        $this->actingAs($reseller)
            ->postJson(route('reseller.domains.renew', $domain), ['years' => 1])
            ->assertOk()
            ->assertJson(['success' => true]);

        $item = array_values(session(CartController::CART_KEY))[0];

        $this->assertSame(2200.0, (float) $item['price']);
        $this->assertSame(1400.0, (float) $item['wholesale_total']);
        $this->assertSame($customer->id, $item['billing_customer_id']);
    }

    public function test_paid_renewal_invoice_pushes_order_to_admin(): void
    {
        $reseller = $this->createResellerWithPackage();

        $extension = DomainExtension::create([
            'extension' => '.net',
            'description' => 'NET',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 1500,
            'renewal_price' => 900,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'renewme',
            'extension' => '.net',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $renewalService = app(DomainRenewalService::class);
        $renewalOrder = $renewalService->initiateResellerRenewal($domain, $reseller, 1);
        $invoice = $renewalService->createInvoice($renewalOrder);

        $payment = Payment::create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        app(ResellerInvoicePaymentService::class)->completeInvoiceIfFullyPaid($invoice, $payment);
        $renewalService->pushRenewalToAdmin($renewalOrder->fresh());

        $renewalOrder->refresh();
        $this->assertSame('pushed', $renewalOrder->status);
        $this->assertNotNull($renewalOrder->admin_order_id);
        $this->assertNotNull($renewalOrder->admin_invoice_id);

        $adminOrder = $renewalOrder->adminOrder;
        $this->assertSame('paid', $adminOrder->payment_status);
        $this->assertSame($renewalOrder->admin_invoice_id, $adminOrder->invoice_id);
    }
}
