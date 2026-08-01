<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeleteReassignTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_with_services_redirects_to_confirm(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['type' => 'container_hosting']);
        Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.delete-confirm', $product));
    }

    public function test_admin_can_reassign_services_and_delete_product(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['type' => 'container_hosting', 'name' => 'Droplet1']);
        $replacement = Product::factory()->create(['type' => 'container_hosting', 'name' => 'Droplet2']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.products.delete-confirm', $product))
            ->assertOk()
            ->assertSee('Move services to this package', false)
            ->assertSee('Droplet2', false);

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product), [
                'replacement_product_id' => $replacement->id,
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertSame($replacement->id, $service->fresh()->product_id);
    }
}
