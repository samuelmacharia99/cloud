<?php

namespace Tests\Feature;

use App\Http\Controllers\Reseller\CartController;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\DomainRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRenewalInvoiceReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_renewal_reuses_existing_open_invoice(): void
    {
        $customer = User::factory()->create();
        $domain = $this->domainWithPricing($customer);
        $renewals = app(DomainRenewalService::class);
        $order = $renewals->initiateRenewal($domain, $customer);
        $invoice = $renewals->createInvoice($order);

        $response = $this->actingAs($customer)->postJson(
            route('customer.domains.initiate-renewal', $domain),
            ['years' => 1]
        );

        $response->assertOk()->assertJson([
            'success' => true,
            'reused_invoice' => true,
            'redirect' => route('customer.checkout.show', ['invoice_id' => $invoice->id]),
        ]);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('domain_renewal_orders', 1);
    }

    public function test_create_invoice_is_idempotent_for_the_same_renewal(): void
    {
        $customer = User::factory()->create();
        $domain = $this->domainWithPricing($customer);
        $renewals = app(DomainRenewalService::class);
        $order = $renewals->initiateRenewal($domain, $customer);

        $first = $renewals->createInvoice($order);
        $second = $renewals->createInvoice($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    public function test_reseller_renewal_reuses_existing_open_invoice_instead_of_carting_again(): void
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
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
        $domain = $this->domainWithPricing($reseller);
        $renewals = app(DomainRenewalService::class);
        $order = $renewals->initiateResellerRenewal($domain, $reseller);
        $invoice = $renewals->createInvoice($order);

        $response = $this->actingAs($reseller)->postJson(
            route('reseller.domains.renew', $domain),
            ['years' => 1]
        );

        $response->assertOk()->assertJson([
            'success' => true,
            'reused_invoice' => true,
            'redirect' => route('reseller.invoices.show', $invoice),
        ]);
        $this->assertEmpty(session(CartController::CART_KEY, []));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('domain_renewal_orders', 1);
    }

    public function test_reseller_reuses_managed_customers_existing_renewal_invoice(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Managed',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $domain = $this->domainWithPricing($customer);
        $domain->update(['reseller_id' => $reseller->id]);

        $renewals = app(DomainRenewalService::class);
        $order = $renewals->initiateManagedCustomerRenewal(
            $domain,
            $reseller,
            $customer,
            1,
            1000,
            1500
        );
        $invoice = $renewals->createInvoice($order);

        $response = $this->actingAs($reseller)->postJson(
            route('reseller.domains.renew', $domain),
            ['years' => 1]
        );

        $response->assertOk()->assertJson([
            'success' => true,
            'reused_invoice' => true,
            'redirect' => route('reseller.customer-invoices.show', $invoice),
        ]);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('domain_renewal_orders', 1);
    }

    private function domainWithPricing(User $owner): Domain
    {
        $extension = DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );
        DomainPricing::query()->create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 1200,
            'renewal_price' => 1000,
            'enabled' => true,
        ]);
        DomainPricing::query()->create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => 1400,
            'renewal_price' => 1200,
            'enabled' => true,
        ]);

        return Domain::create([
            'user_id' => $owner->id,
            'reseller_id' => $owner->is_reseller ? $owner->id : $owner->reseller_id,
            'name' => 'reuse-'.$owner->id,
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addMonth(),
        ]);
    }
}
