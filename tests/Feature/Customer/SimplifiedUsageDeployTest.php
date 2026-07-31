<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimplifiedUsageDeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_customer_sees_simplified_confirm_without_package_grid(): void
    {
        config(['usage_billing.enabled' => true]);

        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $language = ContainerTemplate::factory()->create(['slug' => 'wordpress', 'is_active' => true]);
        Product::factory()->containerHosting()->create([
            'container_template_id' => $language->id,
            'is_active' => true,
            'monthly_price' => 2000,
        ]);
        Product::factory()->create([
            'type' => 'email_hosting',
            'is_active' => true,
            'monthly_price' => 500,
            'provisioning_driver_key' => 'mailcow',
        ]);

        $this->actingAs($customer)
            ->withSession([
                'selected_techstack' => [
                    'language_id' => $language->id,
                    'language_name' => $language->name,
                    'hosting_type' => 'container',
                    'deployment_platform' => 'container',
                ],
            ])
            ->get(route('customer.confirm-techstack'))
            ->assertOk()
            ->assertSee('Almost ready', false)
            ->assertSee('Monthly starter', false)
            ->assertDontSee('Choose Your Hosting Package', false);
    }

    public function test_continue_usage_deploy_adds_cart_item_and_redirects_checkout(): void
    {
        config(['usage_billing.enabled' => true]);

        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $language = ContainerTemplate::factory()->create(['is_active' => true]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $language->id,
            'is_active' => true,
            'monthly_price' => 2000,
        ]);
        Product::factory()->create([
            'type' => 'email_hosting',
            'is_active' => true,
            'provisioning_driver_key' => 'mailcow',
        ]);

        $response = $this->actingAs($customer)
            ->withSession([
                'selected_techstack' => [
                    'language_id' => $language->id,
                    'language_name' => $language->name,
                    'hosting_type' => 'container',
                    'deployment_platform' => 'container',
                ],
            ])
            ->post(route('customer.confirm-techstack.usage'), [
                'primary_domain' => 'example.com',
            ]);

        $response->assertRedirect(route('customer.checkout.show'));

        $cart = session('cart', []);
        $this->assertNotEmpty($cart);
        $item = reset($cart);
        $this->assertTrue($item['usage_billing']);
        $this->assertSame($product->id, $item['product_id']);
        $this->assertSame('example.com', $item['primary_domain']);
        $this->assertSame('monthly', $item['billing_cycle']);
    }
}
