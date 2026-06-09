<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_users' => 10,
            'price' => 500,
            'active' => true,
            'disk_pool_gb' => 50,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_admin_catalog_lists_vps_dedicated_and_container_packages(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create([
            'name' => 'Node.js',
            'slug' => 'nodejs',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);

        Product::factory()->create([
            'name' => 'Node Starter',
            'type' => 'container_hosting',
            'container_template_id' => $template->id,
            'visible_to_resellers' => true,
            'is_active' => true,
            'resource_limits' => ['cpu' => 1, 'memory' => 512, 'disk' => 10],
        ]);

        Product::factory()->create([
            'name' => 'Basic VPS',
            'type' => 'vps',
            'visible_to_resellers' => true,
            'is_active' => true,
        ]);

        Product::factory()->create([
            'name' => 'Metal Box',
            'type' => 'dedicated_server',
            'visible_to_resellers' => true,
            'is_active' => true,
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.catalog.create'))
            ->assertOk()
            ->assertSee('Product type')
            ->assertSee('Container hosting')
            ->assertSee('Node Starter')
            ->assertSee('Basic VPS')
            ->assertSee('Metal Box')
            ->assertSee('Container billing')
            ->assertDontSee('Container template / tech stack');
    }

    public function test_reseller_adds_container_package_from_admin_catalog_with_retail_price_only(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create([
            'name' => 'Python',
            'slug' => 'python',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);

        $product = Product::factory()->containerHosting()->create([
            'name' => 'Python Basic',
            'container_template_id' => $template->id,
            'visible_to_resellers' => true,
            'is_active' => true,
            'resource_limits' => ['cpu' => 2, 'memory' => 1024, 'disk' => 20],
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'product_id' => $product->id,
                'name' => $product->name,
                'description' => 'Retail Python hosting',
                'type' => 'container_hosting',
                'monthly_price' => 29.99,
                'is_active' => true,
            ])
            ->assertRedirect(route('reseller.catalog.index'));

        $listing = ResellerProduct::query()->where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($listing);
        $this->assertSame($product->id, $listing->product_id);
        $this->assertSame($template->id, $listing->container_template_id);
        $this->assertSame('container_hosting', $listing->type);
        $this->assertNull($listing->resource_limits);
        $this->assertSame(29.99, (float) $listing->monthly_price);
    }

    public function test_container_listing_requires_admin_package(): void
    {
        $reseller = $this->reseller();

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Orphan Container',
                'type' => 'container_hosting',
                'monthly_price' => 19.99,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('product_id');
    }

    public function test_catalog_index_shows_tech_stack_for_container_listings(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create([
            'name' => 'Go',
            'slug' => 'go',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'visible_to_resellers' => true,
            'is_active' => true,
        ]);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'container_template_id' => $template->id,
            'name' => 'Go Retail Plan',
            'type' => 'container_hosting',
            'monthly_price' => 39,
            'is_active' => true,
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.catalog.index'))
            ->assertOk()
            ->assertSee('Go')
            ->assertSee('Go Retail Plan');
    }
}
