<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\ServiceBrowserController;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailHostingOrderPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_email_hosting_order_page_with_active_plans(): void
    {
        $customer = User::factory()->customer()->create();
        $plan = Product::factory()->emailHosting()->create([
            'name' => 'Mail Starter',
            'monthly_price' => 499,
            'is_active' => true,
        ]);
        Product::factory()->emailHosting()->create([
            'name' => 'Inactive Mail',
            'is_active' => false,
        ]);
        Product::factory()->containerHosting()->create([
            'name' => 'App Plan',
            'is_active' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.email-hosting'))
            ->assertOk()
            ->assertSee('Email Hosting')
            ->assertSee('Mail Starter')
            ->assertSee('Add to Cart')
            ->assertDontSee('Inactive Mail')
            ->assertDontSee('App Plan');

        $this->assertTrue($plan->is_active);
    }

    public function test_customer_can_add_email_hosting_plan_to_cart(): void
    {
        $customer = User::factory()->customer()->create();
        $plan = Product::factory()->emailHosting()->create([
            'name' => 'Mail Pro',
            'is_active' => true,
        ]);

        $this->actingAs($customer)
            ->post(route('customer.cart.add'), [
                'type' => 'product',
                'product_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertRedirect();

        $cart = \App\Support\SessionCart::portal();
        $this->assertNotEmpty($cart);
        $item = collect($cart)->first();
        $this->assertSame('product', $item['type']);
        $this->assertSame($plan->id, $item['product_id']);
        $this->assertSame('monthly', $item['billing_cycle']);
    }

    public function test_reseller_customer_is_redirected_from_email_hosting_order_page(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($customer)
            ->get(route('customer.email-hosting'))
            ->assertRedirect(route('customer.catalog.index'));
    }

    public function test_techstack_product_resolution_excludes_email_hosting(): void
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create([
            'is_active' => true,
            'slug' => 'nodejs',
        ]);

        Product::factory()->emailHosting()->create([
            'name' => 'Should Not Appear',
            'is_active' => true,
        ]);
        Product::factory()->containerHosting()->create([
            'name' => 'Node App Plan',
            'is_active' => true,
            'container_template_id' => $template->id,
        ]);

        $controller = app(ServiceBrowserController::class);
        $method = new \ReflectionMethod($controller, 'resolveTechstackProducts');
        $method->setAccessible(true);

        $products = $method->invoke(
            $controller,
            $customer,
            $template,
            null,
            ['hosting_type' => 'container'],
        );

        $names = $products->pluck('name')->all();
        $this->assertContains('Node App Plan', $names);
        $this->assertNotContains('Should Not Appear', $names);
    }

    public function test_api_products_rejects_email_hosting_type(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->getJson(route('api.products', ['type' => 'email_hosting']))
            ->assertStatus(422);
    }
}
