<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerProvisionProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerProvisionProductResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_linked_admin_product(): void
    {
        $adminProduct = Product::factory()->create(['is_active' => true]);
        $listing = ResellerProduct::create([
            'reseller_id' => User::factory()->reseller()->create()->id,
            'product_id' => $adminProduct->id,
            'name' => 'Linked Plan',
            'type' => 'shared_hosting',
            'monthly_price' => 10,
            'is_active' => true,
        ]);

        $resolved = app(ResellerProvisionProductResolver::class)->resolve($listing);

        $this->assertSame($adminProduct->id, $resolved?->id);
    }

    public function test_resolves_shell_product_for_directadmin_package_listing(): void
    {
        $listing = ResellerProduct::create([
            'reseller_id' => User::factory()->reseller()->create()->id,
            'product_id' => null,
            'name' => 'Bronze Hosting',
            'type' => 'shared_hosting',
            'direct_admin_package_name' => 'Bronze',
            'monthly_price' => 10,
            'is_active' => true,
        ]);

        $resolver = app(ResellerProvisionProductResolver::class);
        $resolved = $resolver->resolve($listing);

        $this->assertNotNull($resolved);
        $this->assertSame('shared_hosting', $resolved->type);
        $this->assertSame('directadmin', $resolved->provisioning_driver_key);
        $this->assertTrue($listing->isOrderable());
        $this->assertSame($resolved->id, $resolver->shellDirectAdminProduct()->id);
    }
}
