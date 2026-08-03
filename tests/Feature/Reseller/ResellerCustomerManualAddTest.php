<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerCustomerManualAddTest extends TestCase
{
    use RefreshDatabase;

    private function resellerWithCustomer(): array
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_users' => 10,
            'max_services' => 50,
            'price' => 500,
            'active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);

        $customer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
        ]);

        return [$reseller, $customer];
    }

    public function test_reseller_can_manually_add_domain_to_customer(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        $response = $this->actingAs($reseller)->post(route('reseller.customers.add-domain', $customer), [
            'domain_name' => 'manualbiz.com',
            'status' => 'active',
            'expires_at' => now()->addYear()->format('Y-m-d'),
            'auto_renew' => false,
        ]);

        $response->assertRedirect(route('reseller.customers.show', $customer));

        $domain = Domain::query()->where('name', 'manualbiz')->where('extension', '.com')->first();
        $this->assertNotNull($domain);
        $this->assertSame($customer->id, $domain->user_id);
        $this->assertSame($reseller->id, $domain->reseller_id);
        $this->assertSame('dns', $domain->type);
    }

    public function test_reseller_cannot_add_domain_to_another_resellers_customer(): void
    {
        [$reseller] = $this->resellerWithCustomer();
        $otherReseller = User::factory()->reseller()->create();
        $foreignCustomer = User::factory()->customer()->create([
            'reseller_id' => $otherReseller->id,
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.customers.add-domain', $foreignCustomer), [
                'domain_name' => 'stolen.com',
                'status' => 'active',
                'expires_at' => now()->addYear()->format('Y-m-d'),
            ])
            ->assertNotFound();
    }

    public function test_reseller_can_manually_add_service_without_billing(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();

        $adminProduct = Product::factory()->create([
            'type' => 'vps',
            'name' => 'VPS Basic',
            'is_active' => true,
            'visible_to_resellers' => true,
        ]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => 'My VPS',
            'type' => 'vps',
            'monthly_price' => 1500,
            'yearly_price' => 15000,
            'is_active' => true,
        ]);

        Setting::setValue('auto_provision', 'false');
        Setting::setValue('reseller_auto_provision_hosting', 'false');

        $response = $this->actingAs($reseller)->post(route('reseller.customers.add-service', $customer), [
            'reseller_product_id' => $listing->id,
            'billing_cycle' => 'monthly',
            'order_type' => 'provision',
            'bill_customer' => '0',
        ]);

        $response->assertRedirect(route('reseller.customers.show', $customer));
        $this->assertDatabaseHas('services', [
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
        ]);
    }

    public function test_reseller_customer_show_page_has_add_buttons(): void
    {
        [$reseller, $customer] = $this->resellerWithCustomer();

        $this->actingAs($reseller)
            ->get(route('reseller.customers.show', $customer))
            ->assertOk()
            ->assertSee('Add domain', false)
            ->assertSee('Add service', false)
            ->assertSee(route('reseller.customers.add-domain', $customer), false)
            ->assertSee(route('reseller.customers.add-service', $customer), false);
    }
}
