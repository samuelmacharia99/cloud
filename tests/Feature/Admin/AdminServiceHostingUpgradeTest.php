<?php

namespace Tests\Feature\Admin;

use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Customer\CustomerHostingUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceHostingUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_plan_change_uses_package_on_service_node_not_product_default(): void
    {
        $nodeA = Node::factory()->create(['type' => 'directadmin', 'name' => 'Server A']);
        $nodeB = Node::factory()->create(['type' => 'directadmin', 'name' => 'Server B']);

        $bronzeOnA = DirectAdminPackage::create([
            'name' => 'Bronze',
            'package_key' => 'bronze',
            'node_id' => $nodeA->id,
            'disk_quota' => 5,
            'bandwidth_quota' => 50,
            'is_active' => true,
        ]);

        $silverOnB = DirectAdminPackage::create([
            'name' => 'Silver',
            'package_key' => 'silver',
            'node_id' => $nodeB->id,
            'disk_quota' => 20,
            'bandwidth_quota' => 200,
            'is_active' => true,
        ]);

        $bronzeProduct = Product::factory()->create([
            'name' => 'Bronze',
            'slug' => 'bronze-hosting',
            'type' => 'shared_hosting',
            'direct_admin_package_id' => $bronzeOnA->id,
            'monthly_price' => 500,
            'order' => 1,
        ]);

        $silverProduct = Product::factory()->create([
            'name' => 'Silver',
            'slug' => 'silver-hosting',
            'type' => 'shared_hosting',
            'direct_admin_package_id' => $bronzeOnA->id,
            'monthly_price' => 1500,
            'order' => 2,
        ]);

        $customer = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $bronzeProduct->id,
            'node_id' => $nodeB->id,
            'status' => 'active',
            'provisioning_driver_key' => 'directadmin',
            'service_meta' => [
                'username' => 'cust001',
                'package_name' => 'Bronze',
            ],
        ]);

        $upgradeService = app(CustomerHostingUpgradeService::class);
        $method = (new \ReflectionClass(CustomerHostingUpgradeService::class))
            ->getMethod('resolveDirectAdminPackageForProductOnNode');
        $method->setAccessible(true);

        $resolved = $method->invoke($upgradeService, $silverProduct->fresh('directAdminPackage'), $nodeB->id);

        $this->assertNotNull($resolved);
        $this->assertSame($silverOnB->id, $resolved->id);
        $this->assertSame($nodeB->id, (int) $resolved->node_id);
    }

    public function test_sync_platform_product_from_direct_admin_updates_service_product(): void
    {
        $node = Node::factory()->create(['type' => 'directadmin']);

        $bronzePackage = DirectAdminPackage::create([
            'name' => 'Bronze',
            'package_key' => 'bronze',
            'node_id' => $node->id,
            'disk_quota' => 5,
            'bandwidth_quota' => 50,
            'is_active' => true,
        ]);

        $silverPackage = DirectAdminPackage::create([
            'name' => 'silver',
            'package_key' => 'silver',
            'node_id' => $node->id,
            'disk_quota' => 20,
            'bandwidth_quota' => 200,
            'is_active' => true,
        ]);

        $bronzeProduct = Product::factory()->create([
            'name' => 'Bronze',
            'slug' => 'bronze-hosting',
            'type' => 'shared_hosting',
            'direct_admin_package_id' => $bronzePackage->id,
        ]);

        Product::factory()->create([
            'name' => 'Silver',
            'slug' => 'silver-hosting',
            'type' => 'shared_hosting',
            'direct_admin_package_id' => $silverPackage->id,
        ]);

        $service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $bronzeProduct->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'directadmin',
            'service_meta' => [
                'username' => 'cust002',
                'directadmin_account' => ['package' => 'silver'],
            ],
        ]);

        $result = app(CustomerHostingUpgradeService::class)->syncPlatformProductFromDirectAdmin($service->fresh());

        $this->assertTrue($result['changed']);
        $this->assertSame('Silver', $result['product']->name);

        $service->refresh();
        $this->assertSame('Silver', $service->product->name);
        $this->assertSame('silver', $service->service_meta['package_name']);
    }
}
