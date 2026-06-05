<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\CartController;
use App\Models\DomainExtension;
use App\Models\Product;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCustomerCatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_customer_is_redirected_from_platform_browse_to_reseller_catalog(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($customer)
            ->get(route('customer.browse-services'))
            ->assertRedirect(route('customer.reseller-catalog.index'));
    }

    public function test_reseller_customer_sees_only_reseller_catalog_products(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $adminProduct = Product::factory()->create(['is_active' => true, 'monthly_price' => 999]);
        $otherReseller = User::factory()->create(['is_reseller' => true]);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => 'Reseller Plan',
            'type' => $adminProduct->type,
            'monthly_price' => 49.99,
            'is_active' => true,
        ]);

        ResellerProduct::create([
            'reseller_id' => $otherReseller->id,
            'product_id' => Product::factory()->create()->id,
            'name' => 'Other Reseller Plan',
            'type' => 'shared_hosting',
            'monthly_price' => 19.99,
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer)->get(route('customer.reseller-catalog.index'));

        $response->assertOk();
        $response->assertSee('Reseller Plan');
        $response->assertSee('49.99');
        $response->assertDontSee('Other Reseller Plan');
        $response->assertDontSee('KES 999');
        $response->assertDontSee('19.99');
    }

    public function test_reseller_customer_cannot_add_platform_product_to_cart(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create(['is_active' => true]);

        $this->actingAs($customer)
            ->post(route('customer.cart.add'), [
                'type' => 'product',
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertRedirect(route('customer.reseller-catalog.index'));

        $this->assertEmpty(session(CartController::CART_SESSION_KEY, []));
    }

    public function test_reseller_customer_domain_cart_uses_reseller_domain_pricing(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $extension = DomainExtension::create([
            'extension' => '.test',
            'description' => 'Test TLD',
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 1500,
            'enabled' => true,
        ]);

        session([
            CartController::CART_SESSION_KEY => [
                'd1' => [
                    'type' => 'domain',
                    'domain' => 'example',
                    'extension' => '.test',
                    'years' => 1,
                    'nameservers' => ['use_default' => true, 'ns1' => 'ns1.example.com'],
                ],
            ],
        ]);

        $response = $this->actingAs($customer)->get(route('customer.cart.index'));

        $response->assertOk();
        $response->assertSee('1,500');
    }
}
