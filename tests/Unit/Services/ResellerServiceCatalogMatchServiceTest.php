<?php

namespace Tests\Unit\Services;

use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerServiceCatalogMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerServiceCatalogMatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_matches_exact_platform_product_listing(): void
    {
        $reseller = $this->createReseller();

        $product = Product::create([
            'name' => 'Silver Hosting',
            'slug' => 'silver-hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 1200,
            'yearly_price' => 12000,
            'is_active' => true,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'name' => 'Silver Plan',
            'type' => 'shared_hosting',
            'monthly_price' => 1500,
            'yearly_price' => 15000,
            'is_active' => true,
        ]);

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $match = app(ResellerServiceCatalogMatchService::class)->closestMatch($reseller, $service);

        $this->assertNotNull($match);
        $this->assertSame('exact_product', $match['match_type']);
        $this->assertSame($listing->id, $match['listing']->id);
    }

    public function test_picks_closest_shared_hosting_specs_when_no_exact_product(): void
    {
        $reseller = $this->createReseller();
        $node = Node::factory()->create(['type' => 'directadmin']);

        $bronzePackage = DirectAdminPackage::create([
            'name' => 'Bronze DA',
            'package_key' => 'bronze',
            'disk_quota' => 5,
            'bandwidth_quota' => 50,
            'num_databases' => 2,
            'node_id' => $node->id,
            'is_active' => true,
        ]);

        $silverPackage = DirectAdminPackage::create([
            'name' => 'Silver DA',
            'package_key' => 'silver',
            'disk_quota' => 10,
            'bandwidth_quota' => 100,
            'num_databases' => 5,
            'node_id' => $node->id,
            'is_active' => true,
        ]);

        $platformProduct = Product::create([
            'name' => 'Platform Silver',
            'slug' => 'platform-silver-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'direct_admin_package_id' => $silverPackage->id,
            'provisioning_driver_key' => 'directadmin',
            'is_active' => true,
        ]);

        $bronzeProduct = Product::create([
            'name' => 'Bronze Product',
            'slug' => 'bronze-product-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'direct_admin_package_id' => $bronzePackage->id,
            'provisioning_driver_key' => 'directadmin',
            'is_active' => true,
        ]);

        $silverMappedProduct = Product::create([
            'name' => 'Mapped Silver',
            'slug' => 'mapped-silver-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 1100,
            'yearly_price' => 11000,
            'direct_admin_package_id' => $silverPackage->id,
            'provisioning_driver_key' => 'directadmin',
            'is_active' => true,
        ]);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $bronzeProduct->id,
            'name' => 'Reseller Bronze',
            'type' => 'shared_hosting',
            'monthly_price' => 700,
            'yearly_price' => 7000,
            'is_active' => true,
        ]);

        $silverListing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $silverMappedProduct->id,
            'name' => 'Reseller Silver',
            'type' => 'shared_hosting',
            'monthly_price' => 1200,
            'yearly_price' => 12000,
            'is_active' => true,
        ]);

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $platformProduct->id,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $match = app(ResellerServiceCatalogMatchService::class)->closestMatch($reseller, $service);

        $this->assertNotNull($match);
        $this->assertSame('closest_specs', $match['match_type']);
        $this->assertSame($silverListing->id, $match['listing']->id);
    }
}
