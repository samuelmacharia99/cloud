<?php

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use App\Services\ResellerDirectAdminService;
use App\Services\ResellerHostedAccountDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ResellerHostedAccountDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_directory_lists_directadmin_users_and_marks_unlinked(): void
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

        $linkedCustomer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Linked Customer',
            'email' => 'linked@example.test',
        ]);

        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        Service::factory()->create([
            'user_id' => $linkedCustomer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'linkeduser',
            'service_meta' => ['username' => 'linkeduser', 'domain' => 'linked.example'],
        ]);

        $daMock = Mockery::mock(DirectAdminService::class);
        $daMock->shouldReceive('isConfigured')->andReturn(true);
        $daMock->shouldReceive('listUsersOwnedByReseller')
            ->with('res_acme')
            ->andReturn(['linkeduser', 'orphanuser']);
        $daMock->shouldReceive('getAccountDirectoryEntry')
            ->with('linkeduser')
            ->andReturn([
                'username' => 'linkeduser',
                'domain' => 'linked.example',
                'package' => 'bronze',
                'email' => 'linked@example.test',
                'name' => 'Linked Customer',
                'suspended' => false,
            ]);
        $daMock->shouldReceive('getAccountDirectoryEntry')
            ->with('orphanuser')
            ->andReturn([
                'username' => 'orphanuser',
                'domain' => 'orphan.example',
                'package' => 'silver',
                'email' => 'orphan@example.test',
                'name' => 'Orphan User',
                'suspended' => false,
            ]);

        $this->mock(ResellerDirectAdminService::class, function ($mock) use ($daMock) {
            $mock->shouldReceive('hasDirectAdminBinding')->andReturn(true);
            $mock->shouldReceive('directAdmin')->andReturn($daMock);
        });

        ResellerProduct::query()->create([
            'reseller_id' => $reseller->id,
            'type' => 'shared_hosting',
            'name' => 'Silver',
            'direct_admin_package_name' => 'silver',
            'monthly_price' => 1000,
            'yearly_price' => 10000,
            'is_active' => true,
        ]);

        $result = app(ResellerHostedAccountDirectoryService::class)
            ->paginatedForReseller($reseller, Request::create('/reseller/customers', 'GET'));

        $this->assertTrue($result['uses_directadmin']);
        $this->assertSame(2, $result['stats']['total']);
        $this->assertSame(1, $result['stats']['linked']);
        $this->assertSame(1, $result['stats']['unlinked']);

        $rows = collect($result['rows']->items());
        $orphan = $rows->firstWhere('da_username', 'orphanuser');
        $this->assertNotNull($orphan);
        $this->assertSame('unlinked', $orphan['link_status']);
        $this->assertSame('package_detected', $orphan['billing_status']);
        $this->assertNull($orphan['user']);
    }

    public function test_admin_directory_marks_platform_customer_with_directadmin_service_as_linked(): void
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

        $platformCustomer = User::factory()->customer()->create([
            'reseller_id' => null,
            'name' => 'Copyrite Furnitures Ltd.',
            'email' => 'furniturenairobi@gmail.com',
            'company' => 'Copyrite Furnitures Ltd.',
        ]);

        $product = Product::factory()->create([
            'name' => 'Gold',
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        Service::factory()->create([
            'user_id' => $platformCustomer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'directadmin',
            'external_reference' => 'copyrite',
            'service_meta' => [
                'username' => 'copyrite',
                'domain' => 'copyritefurniturekenya.com',
                'package' => 'gold',
                'package_name' => 'Gold',
            ],
        ]);

        $daMock = Mockery::mock(DirectAdminService::class);
        $daMock->shouldReceive('isConfigured')->andReturn(true);
        $daMock->shouldReceive('listUsersOwnedByReseller')
            ->with('res_acme')
            ->andReturn([]);
        $this->mock(ResellerDirectAdminService::class, function ($mock) use ($reseller, $daMock, $node) {
            $mock->shouldReceive('hasDirectAdminBinding')->andReturn(true);
            $mock->shouldReceive('directAdmin')->andReturn($daMock);
            $mock->shouldReceive('resolveNode')->with($reseller)->andReturn($node);
        });

        $result = app(ResellerHostedAccountDirectoryService::class)
            ->paginatedForAdmin(Request::create('/admin/customers', 'GET'));

        $row = collect($result['rows']->items())
            ->firstWhere('display_email', 'furniturenairobi@gmail.com');

        $this->assertNotNull($row);
        $this->assertSame('linked', $row['link_status']);
        $this->assertSame('ready', $row['billing_status']);
        $this->assertSame('copyrite', $row['da_username']);
        $this->assertSame('copyritefurniturekenya.com', $row['da_domain']);
        $this->assertSame('Gold', $row['da_package']);
    }

    public function test_admin_directory_orders_customers_newest_first(): void
    {
        $older = User::factory()->customer()->create([
            'name' => 'Older Customer',
            'email' => 'older@example.test',
            'created_at' => now()->subDays(10),
        ]);
        $newer = User::factory()->customer()->create([
            'name' => 'Newer Customer',
            'email' => 'newer@example.test',
            'created_at' => now()->subDay(),
        ]);

        $result = app(ResellerHostedAccountDirectoryService::class)
            ->paginatedForAdmin(Request::create('/admin/customers', 'GET'));

        $items = collect($result['rows']->items());
        $emails = $items->map(function ($row) {
            if ($row instanceof User) {
                return $row->email;
            }

            return $row['display_email'] ?? null;
        })->filter()->values();

        $this->assertGreaterThanOrEqual(2, $emails->count());
        $this->assertSame('newer@example.test', $emails[0]);
        $this->assertContains('older@example.test', $emails->all());
        $this->assertLessThan($emails->search('older@example.test'), $emails->search('newer@example.test'));
    }
}
