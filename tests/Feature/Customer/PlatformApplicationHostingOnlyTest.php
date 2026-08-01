<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformApplicationHostingOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function seedLaravelStack(): array
    {
        $language = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel app',
                'category' => 'web',
                'docker_image' => 'laravel:latest',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 5,
                'is_active' => true,
                'order' => 1,
            ]
        );
        $language->forceFill(['hosting_type' => 'directadmin'])->save();

        $containerDb = DatabaseTemplate::query()->updateOrCreate(
            ['slug' => 'mysql-container-test'],
            [
                'name' => 'MySQL Container',
                'description' => 'Container MySQL',
                'type' => 'mysql',
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 1,
            ]
        );

        DatabaseTemplate::query()->updateOrCreate(
            ['slug' => 'mysql-directadmin-test'],
            [
                'name' => 'MySQL DirectAdmin',
                'description' => 'DA MySQL',
                'type' => 'mysql',
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => 'directadmin',
                'is_active' => true,
                'order' => 2,
            ]
        );

        Product::factory()->create([
            'type' => 'shared_hosting',
            'name' => 'Shared Plan',
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $appProduct = Product::factory()->create([
            'type' => 'container_hosting',
            'name' => 'App Plan',
            'is_active' => true,
            'container_template_id' => $language->id,
            'provisioning_driver_key' => 'container',
            'monthly_price' => 19.99,
        ]);

        return [$language->fresh(), $containerDb->fresh(), $appProduct];
    }

    public function test_techstack_confirm_rejects_shared_platform(): void
    {
        [$language, $containerDb] = $this->seedLaravelStack();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => $containerDb->id,
                'deployment_platform' => 'shared',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_techstack_confirm_queues_application_checkout_not_shared(): void
    {
        [$language, $containerDb, $appProduct] = $this->seedLaravelStack();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'database_id' => $containerDb->id,
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.checkout.show'));

        $cart = session('cart', []);
        $this->assertNotEmpty($cart);
        $item = reset($cart);
        $this->assertSame($appProduct->id, $item['product_id']);
        $this->assertTrue($item['usage_billing'] ?? false);

        $this->actingAs($customer)
            ->get(route('customer.checkout.show'))
            ->assertOk()
            ->assertSee('Application domain', false)
            ->assertDontSee('Shared Plan', false);
    }

    public function test_language_databases_api_excludes_directadmin(): void
    {
        [$language] = $this->seedLaravelStack();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $response = $this->actingAs($customer)
            ->getJson(route('api.languages.databases', $language))
            ->assertOk()
            ->json('databases');

        $slugs = collect($response)->pluck('slug');
        $this->assertTrue($slugs->contains('mysql-container-test'));
        $this->assertFalse($slugs->contains('mysql-directadmin-test'));
    }

    public function test_platform_customer_cannot_add_shared_hosting_to_cart(): void
    {
        $this->seedLaravelStack();
        $shared = Product::query()->where('type', 'shared_hosting')->first();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $this->actingAs($customer)
            ->post(route('customer.cart.add'), [
                'type' => 'product',
                'product_id' => $shared->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEmpty(session('cart', []));
    }

    public function test_browse_excludes_shared_hosting(): void
    {
        $this->seedLaravelStack();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $this->actingAs($customer)
            ->get(route('customer.browse-services'))
            ->assertOk()
            ->assertSee('App Plan')
            ->assertDontSee('Shared Plan');
    }
}
