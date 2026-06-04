<?php

namespace Tests\Unit\Services;

use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\Product;
use App\Models\User;
use App\Services\ResellerHostingSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerHostingSetupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_directadmin_context_under_reseller_node(): void
    {
        $node = Node::create([
            'name' => 'DA',
            'hostname' => 'da.test',
            'ip_address' => '10.0.0.2',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.test:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'key',
            'is_active' => true,
        ]);

        $package = DirectAdminPackage::create([
            'name' => 'Starter',
            'package_key' => 'starter',
            'node_id' => $node->id,
            'disk_quota' => 1000,
            'bandwidth_quota' => 10000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Shared',
            'slug' => 'shared-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 100,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $package->id,
            'is_active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'directadmin_username' => 'reseller1',
            'reseller_node_id' => $node->id,
        ]);

        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $context = app(ResellerHostingSetupService::class)->buildProvisioningContext(
            $reseller,
            $customer,
            $product,
            'site.example.com',
        );

        $this->assertSame($node->id, $context['node_id']);
        $this->assertSame('directadmin', $context['provisioning_driver_key']);
        $this->assertSame('site.example.com', $context['service_meta']['domain']);
        $this->assertSame('reseller1', $context['service_meta']['directadmin_reseller']);
        $this->assertSame('starter', $context['service_meta']['package']);
    }

    public function test_rejects_shared_hosting_without_reseller_directadmin_binding(): void
    {
        $node = Node::create([
            'name' => 'DA',
            'hostname' => 'da.test',
            'ip_address' => '10.0.0.3',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.test:2222',
            'is_active' => true,
        ]);

        $package = DirectAdminPackage::create([
            'name' => 'Bronze',
            'package_key' => 'bronze',
            'node_id' => $node->id,
            'disk_quota' => 1000,
            'bandwidth_quota' => 10000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Shared',
            'slug' => 'shared-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 100,
            'provisioning_driver_key' => 'directadmin',
            'direct_admin_package_id' => $package->id,
            'is_active' => true,
        ]);

        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DirectAdmin reseller');

        app(ResellerHostingSetupService::class)->buildProvisioningContext(
            $reseller,
            $customer,
            $product,
            'site.example.com',
        );
    }
}
