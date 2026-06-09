<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
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
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function containerDatabase(string $slug = 'postgresql-test'): DatabaseTemplate
    {
        return DatabaseTemplate::create([
            'name' => 'PostgreSQL',
            'slug' => $slug,
            'description' => 'Test database',
            'type' => 'postgresql',
            'versions' => ['16'],
            'docker_image' => 'postgres:16',
            'default_port' => 5432,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);
    }

    public function test_admin_catalog_section_only_lists_vps_and_dedicated_servers(): void
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
            ->assertSee('Server type')
            ->assertSee('Basic VPS')
            ->assertSee('Metal Box')
            ->assertSee('Container template / tech stack')
            ->assertSee('Node.js')
            ->assertDontSee('Platform container plan')
            ->assertDontSee('Hosting category')
            ->assertDontSee('Container hosting (tech stack plans)');
    }

    public function test_reseller_can_add_container_product_via_custom_product_flow(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create([
            'name' => 'Python',
            'slug' => 'python',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);
        $database = $this->containerDatabase('postgresql-python-test');

        $product = Product::factory()->containerHosting()->create([
            'name' => 'Python Basic',
            'container_template_id' => $template->id,
            'visible_to_resellers' => false,
            'is_active' => true,
            'wholesale_monthly_price' => 15,
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'container_template_id' => $template->id,
                'database_template_id' => $database->id,
                'name' => 'Python Retail',
                'description' => 'Retail Python hosting',
                'type' => 'container_hosting',
                'resource_limits' => [
                    'cpu' => 1,
                    'memory_mb' => 512,
                    'disk_gb' => 10,
                ],
                'monthly_price' => 29.99,
                'is_active' => true,
            ])
            ->assertRedirect(route('reseller.catalog.index'));

        $listing = ResellerProduct::query()->where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($listing);
        $this->assertSame($product->id, $listing->product_id);
        $this->assertSame($template->id, $listing->container_template_id);
        $this->assertSame($database->id, $listing->database_template_id);
        $this->assertSame('container_hosting', $listing->type);
        $this->assertSame('Python Retail', $listing->name);
        $this->assertSame(10.0, (float) ($listing->resource_limits['disk_gb'] ?? 0));
    }

    public function test_container_listing_requires_tech_stack_and_database(): void
    {
        $reseller = $this->reseller();

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Orphan Container',
                'type' => 'container_hosting',
                'monthly_price' => 19.99,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('container_template_id');
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
