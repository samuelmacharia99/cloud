<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductToggleActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_toggle_product_active_from_index(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'name' => 'Old Droplet Plan',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.products.index'))
            ->post(route('admin.products.toggle-active', $product))
            ->assertRedirect(route('admin.products.index'));

        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($admin)
            ->from(route('admin.products.index', ['status' => 'inactive']))
            ->post(route('admin.products.toggle-active', $product))
            ->assertRedirect(route('admin.products.index', ['status' => 'inactive']));

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_customer_cannot_toggle_product_active(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['is_active' => true]);

        $this->actingAs($customer)
            ->post(route('admin.products.toggle-active', $product))
            ->assertForbidden();

        $this->assertTrue($product->fresh()->is_active);
    }
}
