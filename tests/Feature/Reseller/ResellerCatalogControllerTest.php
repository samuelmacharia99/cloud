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
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_create_page_shows_container_tech_stack_filters(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create(['name' => 'Node.js', 'is_active' => true]);

        Product::factory()->create([
            'name' => 'Node Starter',
            'type' => 'container_hosting',
            'container_template_id' => $template->id,
            'visible_to_resellers' => true,
            'is_active' => true,
            'wholesale_monthly_price' => 20,
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.catalog.create'))
            ->assertOk()
            ->assertSee('Tech stack / language')
            ->assertSee('Node.js')
            ->assertSee('How container catalog items work');
    }

    public function test_reseller_can_add_container_product_linked_to_tech_stack(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create(['name' => 'Python', 'is_active' => true]);

        $product = Product::factory()->containerHosting()->create([
            'name' => 'Python Basic',
            'container_template_id' => $template->id,
            'visible_to_resellers' => true,
            'is_active' => true,
            'wholesale_monthly_price' => 15,
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'product_id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'type' => 'container_hosting',
                'monthly_price' => 29.99,
                'is_active' => true,
            ])
            ->assertRedirect(route('reseller.catalog.index'));

        $listing = ResellerProduct::query()->where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($listing);
        $this->assertSame($product->id, $listing->product_id);
        $this->assertSame('container_hosting', $listing->type);
    }

    public function test_catalog_index_shows_tech_stack_for_container_listings(): void
    {
        $reseller = $this->reseller();
        $template = ContainerTemplate::factory()->create(['name' => 'Go', 'is_active' => true]);

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'visible_to_resellers' => true,
            'is_active' => true,
        ]);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
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
