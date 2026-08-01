<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerTemplate;
use App\Models\DomainDeploymentLock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimplifiedUsageDeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_customers_are_excluded_from_usage_deploy_flow(): void
    {
        config(['usage_billing.enabled' => true]);

        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $language = ContainerTemplate::factory()->create(['is_active' => true]);
        Product::factory()->containerHosting()->create([
            'container_template_id' => $language->id,
            'is_active' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.select-techstack'))
            ->assertRedirect(route('customer.catalog.index'));

        $this->assertFalse(
            app(\App\Services\Billing\UsageBillingProfileService::class)
                ->shouldUseUsageBillingForCustomer($customer)
        );
    }

    public function test_confirm_techstack_skips_packages_and_goes_to_checkout(): void
    {
        config(['usage_billing.enabled' => true]);

        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $language = ContainerTemplate::factory()->create(['slug' => 'wordpress', 'is_active' => true]);
        $product = Product::factory()->containerHosting()->create([
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
            ->assertRedirect(route('customer.checkout.show'));

        $cart = session('cart', []);
        $this->assertNotEmpty($cart);
        $item = reset($cart);
        $this->assertTrue($item['usage_billing']);
        $this->assertSame($product->id, $item['product_id']);
        $this->assertArrayNotHasKey('primary_domain', $item);

        $this->actingAs($customer)
            ->get(route('customer.checkout.show'))
            ->assertOk()
            ->assertSee('Application domain', false)
            ->assertSee('Register new domain', false)
            ->assertSee('Use existing domain', false)
            ->assertSee('Transfer to us', false)
            ->assertDontSee('Choose Your Hosting Package', false);
    }

    public function test_database_confirm_posts_straight_to_checkout(): void
    {
        config(['usage_billing.enabled' => true]);

        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $language = ContainerTemplate::factory()->create(['is_active' => true, 'slug' => 'nodejs', 'hosting_type' => 'container']);
        $database = \App\Models\DatabaseTemplate::query()->updateOrCreate(
            ['slug' => 'mysql-usage-test'],
            [
                'name' => 'MySQL',
                'description' => 'MySQL',
                'type' => 'mysql',
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 1,
            ]
        );
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
            ->from(route('customer.select-techstack'))
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => $database->id,
                'deployment_platform' => 'container',
            ]);

        $response->assertRedirect(route('customer.checkout.show'));

        $cart = session('cart', []);
        $this->assertNotEmpty($cart);
        $item = reset($cart);
        $this->assertTrue($item['usage_billing']);
        $this->assertTrue($item['usage_free_period']);
        $this->assertSame($product->id, $item['product_id']);
        $this->assertSame('monthly', $item['billing_cycle']);
    }

    public function test_locked_domain_is_rejected_at_checkout_not_at_stack_select(): void
    {
        config(['usage_billing.enabled' => true]);

        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $other = User::factory()->customer()->create(['reseller_id' => null]);
        $language = ContainerTemplate::factory()->create(['is_active' => true, 'slug' => 'nodejs', 'hosting_type' => 'container']);
        $database = \App\Models\DatabaseTemplate::query()->updateOrCreate(
            ['slug' => 'mysql-usage-lock-test'],
            [
                'name' => 'MySQL',
                'description' => 'MySQL',
                'type' => 'mysql',
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 1,
            ]
        );
        Product::factory()->containerHosting()->create([
            'container_template_id' => $language->id,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'type' => 'email_hosting',
            'is_active' => true,
            'provisioning_driver_key' => 'mailcow',
        ]);

        DomainDeploymentLock::create([
            'fqdn' => 'taken.example',
            'user_id' => $other->id,
            'status' => DomainDeploymentLock::STATUS_LOCKED,
            'locked_at' => now(),
        ]);

        $this->actingAs($customer)
            ->from(route('customer.select-techstack'))
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => $database->id,
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.checkout.show'));

        $cart = session('cart', []);
        $key = array_key_first($cart);

        $this->actingAs($customer)
            ->from(route('customer.checkout.show'))
            ->post(route('customer.checkout.process'), [
                'agree_terms' => '1',
                'app_domain_mode' => [$key => 'existing'],
                'app_domain_fqdn' => [$key => 'taken.example'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }
}
