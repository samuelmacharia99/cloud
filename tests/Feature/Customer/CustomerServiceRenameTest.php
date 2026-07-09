<?php

namespace Tests\Feature\Customer;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerServiceRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_rename_own_service(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['name' => 'Starter Hosting']);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Old Label',
            'status' => 'active',
        ]);

        $response = $this->actingAs($customer)->patch(route('customer.services.rename', $service), [
            'name' => 'Production Website',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame('Production Website', $service->fresh()->name);
    }

    public function test_customer_cannot_rename_another_users_service(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Service',
            'status' => 'active',
        ]);

        $this->actingAs($other)->patch(route('customer.services.rename', $service), [
            'name' => 'Hijacked',
        ])->assertForbidden();

        $this->assertSame('Owner Service', $service->fresh()->name);
    }

    public function test_services_index_shows_rename_button(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['name' => 'Starter Hosting', 'type' => 'shared_hosting']);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'My Project',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('My Project')
            ->assertSee('Rename');
    }

    public function test_rename_requires_valid_name(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Valid Name',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->patch(route('customer.services.rename', $service), ['name' => 'A'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Valid Name', $service->fresh()->name);
    }
}
