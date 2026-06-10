<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_duplicate_container_hosting_product(): void
    {
        $admin = User::factory()->admin()->create();
        $template = ContainerTemplate::factory()->create();

        $product = Product::factory()->containerHosting()->create([
            'name' => 'Node App Starter',
            'slug' => 'node-app-starter',
            'container_template_id' => $template->id,
            'monthly_price' => 1500,
            'yearly_price' => 15000,
            'resource_limits' => ['cpu' => 2, 'memory' => 1024, 'disk' => 20],
            'overage_enabled' => true,
            'cpu_overage_rate' => 10,
            'is_active' => true,
            'featured' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.products.duplicate', $product));

        $copy = Product::query()->where('slug', 'node-app-starter-copy')->first();

        $response->assertRedirect(route('admin.products.edit', $copy));
        $this->assertNotNull($copy);
        $this->assertSame('Node App Starter (Copy)', $copy->name);
        $this->assertSame($template->id, $copy->container_template_id);
        $this->assertSame(['cpu' => 2, 'memory' => 1024, 'disk' => 20], $copy->resource_limits);
        $this->assertSame('1500.00', $copy->monthly_price);
        $this->assertFalse($copy->is_active);
        $this->assertFalse($copy->featured);
    }

    public function test_duplicate_is_not_allowed_for_non_container_products(): void
    {
        $admin = User::factory()->admin()->create();

        $product = Product::factory()->create([
            'type' => 'shared_hosting',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.products.duplicate', $product))
            ->assertNotFound();

        $this->assertSame(1, Product::count());
    }

    public function test_duplicate_generates_incremented_name_when_copy_already_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $template = ContainerTemplate::factory()->create();

        $product = Product::factory()->containerHosting()->create([
            'name' => 'API Plan',
            'slug' => 'api-plan',
            'container_template_id' => $template->id,
        ]);

        Product::factory()->containerHosting()->create([
            'name' => 'API Plan (Copy)',
            'slug' => 'api-plan-copy',
            'container_template_id' => $template->id,
        ]);

        $this->actingAs($admin)->post(route('admin.products.duplicate', $product));

        $this->assertDatabaseHas('products', [
            'name' => 'API Plan (Copy 2)',
            'slug' => 'api-plan-copy-2',
        ]);
    }
}
