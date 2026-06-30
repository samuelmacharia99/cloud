<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerHostedAccountLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ResellerHostedAccountLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_account_creates_customer_and_service_without_provisioning(): void
    {
        [$reseller, $daMock] = $this->mockResellerWithDirectAdmin('orphanuser', [
            'username' => 'orphanuser',
            'domain' => 'orphan.example',
            'package' => 'silver',
            'email' => 'orphan@example.test',
            'name' => 'Orphan User',
            'suspended' => false,
        ]);

        ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'type' => 'shared_hosting',
            'name' => 'Silver',
            'direct_admin_package_name' => 'silver',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'is_active' => true,
        ]);

        $result = app(ResellerHostedAccountLinkService::class)->linkAccount($reseller, 'orphanuser', [
            'billing_cycle' => 'annual',
            'country' => 'KE',
        ]);

        $this->assertSame('orphan@example.test', $result['customer']->email);
        $this->assertSame($reseller->id, $result['customer']->reseller_id);
        $this->assertSame('orphanuser', $result['service']->external_reference);
        $this->assertSame(ServiceStatus::Active, $result['service']->status);
        $this->assertTrue($result['service']->service_meta['imported_from_directadmin'] ?? false);
        $this->assertNotEmpty($result['service']->service_meta['reseller_product_id']);
        $this->assertSame(10000.0, (float) $result['service']->custom_price);
    }

    public function test_connect_billing_attaches_catalog_listing_to_linked_service(): void
    {
        [$reseller] = $this->mockResellerWithDirectAdmin('linkeduser', [
            'username' => 'linkeduser',
            'domain' => 'linked.example',
            'package' => 'bronze',
            'email' => 'linked@example.test',
            'name' => 'Linked User',
            'suspended' => false,
        ]);

        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        $listing = ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'type' => 'shared_hosting',
            'name' => 'Bronze',
            'direct_admin_package_name' => 'bronze',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'is_active' => true,
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'node_id' => $reseller->reseller_node_id,
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'linkeduser',
            'service_meta' => ['username' => 'linkeduser'],
        ]);

        $updated = app(ResellerHostedAccountLinkService::class)->connectBilling($reseller, $service, [
            'reseller_product_id' => $listing->id,
            'billing_cycle' => 'annual',
        ]);

        $this->assertSame($listing->id, $updated->service_meta['reseller_product_id']);
        $this->assertSame(5000.0, (float) $updated->custom_price);
    }

    /**
     * @return array{0: User, 1: DirectAdminService&MockInterface}
     */
    private function mockResellerWithDirectAdmin(string $username, array $entry): array
    {
        $node = Node::factory()->create([
            'type' => 'directadmin',
            'api_url' => 'https://da.example.test:2222',
            'is_active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'directadmin_username' => 'res_acme',
            'directadmin_login_key' => 'login-key',
            'reseller_node_id' => $node->id,
        ]);

        $daMock = Mockery::mock(DirectAdminService::class);
        $daMock->shouldReceive('listUsersOwnedByReseller')
            ->with('res_acme')
            ->andReturn([$username]);
        $daMock->shouldReceive('getAccountDirectoryEntry')
            ->with($username)
            ->andReturn($entry);

        $this->mock(ResellerDirectAdminService::class, function ($mock) use ($reseller, $daMock, $node) {
            $mock->shouldReceive('hasDirectAdminBinding')->andReturn(true);
            $mock->shouldReceive('directAdmin')->andReturn($daMock);
            $mock->shouldReceive('resolveNode')->with($reseller)->andReturn($node);
        });

        return [$reseller, $daMock];
    }
}
