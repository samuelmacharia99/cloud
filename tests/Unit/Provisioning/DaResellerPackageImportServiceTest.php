<?php

namespace Tests\Unit\Provisioning;

use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaResellerPackageImportService;
use App\Services\ResellerDirectAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DaResellerPackageImportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_catalog_listings_from_directadmin_packages_without_inventing_prices(): void
    {
        $reseller = $this->reseller();
        $engine = Product::factory()->containerHosting()->create([
            'name' => 'App Medium',
            'resource_limits' => ['cpu' => 1, 'memory' => 1024, 'disk' => 20],
        ]);
        $this->bindPackages($reseller, [
            ['name' => 'Business', 'disk_quota' => 20, 'description' => 'DA business'],
        ]);

        $result = app(DaResellerPackageImportService::class)->import($reseller);

        $this->assertSame(1, $result['created']);
        $listing = ResellerProduct::query()->where('reseller_id', $reseller->id)->first();
        $this->assertNotNull($listing);
        $this->assertSame('Business', $listing->name);
        $this->assertSame('Business', $listing->direct_admin_package_name);
        $this->assertSame('container_hosting', $listing->type);
        $this->assertSame($engine->id, $listing->product_id);
        $this->assertEquals(0, (float) $listing->monthly_price);
    }

    #[Test]
    public function it_keeps_existing_retail_prices_when_retargeting_a_listing(): void
    {
        $reseller = $this->reseller();
        $engine = Product::factory()->containerHosting()->create([
            'resource_limits' => ['disk' => 10],
        ]);
        $existing = ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Business',
            'type' => 'shared_hosting',
            'direct_admin_package_name' => 'Business',
            'monthly_price' => 2500,
            'yearly_price' => 25000,
            'setup_fee' => 0,
            'is_active' => true,
        ]);
        $this->bindPackages($reseller, [
            ['name' => 'Business', 'disk_quota' => 10],
        ]);

        app(DaResellerPackageImportService::class)->import($reseller);

        $existing->refresh();
        $this->assertSame('container_hosting', $existing->type);
        $this->assertSame($engine->id, $existing->product_id);
        $this->assertEquals(2500, (float) $existing->monthly_price);
        $this->assertEquals(25000, (float) $existing->yearly_price);
        $this->assertSame('business', $existing->slug);
    }

    #[Test]
    public function it_maps_a_service_to_the_listing_for_its_da_package(): void
    {
        $reseller = $this->reseller();
        $engine = Product::factory()->containerHosting()->create();
        $listing = ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'product_id' => $engine->id,
            'name' => 'Gold',
            'type' => 'container_hosting',
            'direct_admin_package_name' => 'Gold',
            'monthly_price' => 4000,
            'is_active' => true,
        ]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'custom_price' => 4000,
            'billing_cycle' => 'monthly',
            'service_meta' => ['package_name' => 'Gold', 'username' => 'da-gold'],
        ]);

        $mapped = app(DaResellerPackageImportService::class)->resolveForService($reseller, $service);

        $this->assertSame('Gold', $mapped['da_package']);
        $this->assertSame($listing->id, $mapped['listing']?->id);
        $this->assertSame($engine->id, $mapped['engine']?->id);
        $this->assertEquals(4000, $mapped['retail']);
    }

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
            'disk_pool_gb' => 100,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    private function bindPackages(User $reseller, array $packages): void
    {
        $da = Mockery::mock(ResellerDirectAdminService::class);
        $da->shouldReceive('listAssignablePackages')->with(Mockery::on(fn (User $user) => $user->id === $reseller->id))->andReturn([
            'packages' => $packages,
            'error' => null,
        ]);
        $this->app->instance(ResellerDirectAdminService::class, $da);
    }
}
