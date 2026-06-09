<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\CartController;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
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
            ->assertRedirect(route('customer.catalog.index'));
    }

    public function test_reseller_customer_sees_only_reseller_catalog_products(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $adminProduct = Product::factory()->create([
            'is_active' => true,
            'monthly_price' => 999,
            'type' => 'ssl',
        ]);
        $otherReseller = User::factory()->create(['is_reseller' => true]);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => 'Reseller Plan',
            'type' => 'ssl',
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

        $response = $this->actingAs($customer)->get(route('customer.catalog.index'));

        $response->assertOk();
        $response->assertSee('Reseller Plan');
        $response->assertSee('49.99');
        $response->assertSee('Other services');
        $response->assertDontSee('Other Reseller Plan');
        $response->assertDontSee('KES 999');
        $response->assertDontSee('19.99');
        $response->assertDontSee('Reseller Catalog');
        $response->assertDontSee('your reseller');
        $response->assertDontSee('platform catalog');
    }

    public function test_legacy_reseller_catalog_url_redirects_to_neutral_catalog_path(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($customer)
            ->get('/my/reseller-catalog')
            ->assertRedirect('/my/catalog');
    }

    public function test_reseller_customer_can_access_tech_stack_selection(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($customer)
            ->get(route('customer.select-techstack'))
            ->assertOk();
    }

    public function test_reseller_customer_tech_stack_flow_uses_reseller_pricing_in_cart(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $language = ContainerTemplate::factory()->create([
            'slug' => 'php',
            'is_active' => true,
        ]);
        $language->forceFill(['hosting_type' => 'directadmin'])->save();

        $database = DatabaseTemplate::create([
            'name' => 'MySQL',
            'slug' => 'mysql-shared-test',
            'type' => 'mysql',
            'hosting_type' => 'directadmin',
            'default_port' => 3306,
            'required_ram_mb' => 256,
            'is_active' => true,
            'order' => 0,
        ]);

        $adminProduct = Product::factory()->create([
            'type' => 'shared_hosting',
            'is_active' => true,
            'monthly_price' => 999,
        ]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => 'Reseller Starter',
            'type' => 'shared_hosting',
            'monthly_price' => 79.99,
            'is_active' => true,
        ]);

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack'), [
                'language_id' => $language->id,
                'database_id' => $database->id,
            ])
            ->assertOk()
            ->assertSee('Reseller Starter')
            ->assertDontSee('KES 999');

        $this->actingAs($customer)
            ->post(route('customer.cart.add'), [
                'type' => 'reseller_product',
                'reseller_product_id' => $listing->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertRedirect(route('customer.cart.index'));

        $cart = session(CartController::CART_SESSION_KEY, []);
        $this->assertCount(1, $cart);
        $this->assertSame('reseller_product', array_values($cart)[0]['type']);
        $this->assertSame($listing->id, array_values($cart)[0]['reseller_product_id']);
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
            ->assertRedirect(route('customer.catalog.index'));

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
