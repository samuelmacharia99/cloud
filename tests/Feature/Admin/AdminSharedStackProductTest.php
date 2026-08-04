<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSharedStackProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_shared_application_hosting_plan(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'name' => 'Project Starter',
                'slug' => 'project-starter',
                'type' => 'container_hosting',
                'container_template_id' => '',
                'monthly_price' => 450,
                'yearly_price' => 5400,
                'is_active' => '1',
                'resource_limits' => [
                    'cpu' => 1,
                    'memory' => 2048,
                    'disk' => 50,
                ],
            ])
            ->assertRedirect();

        $product = Product::where('slug', 'project-starter')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->isSharedStackPlan());
        $this->assertNull($product->container_template_id);
        $this->assertSame(2048, (int) ($product->resource_limits['memory'] ?? 0));
    }
}
