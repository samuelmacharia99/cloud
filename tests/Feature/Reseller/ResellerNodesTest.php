<?php

namespace Tests\Feature\Reseller;

use App\Models\Node;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerNodesTest extends TestCase
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

    private function directAdminNode(): Node
    {
        return Node::factory()->create([
            'type' => 'directadmin',
            'is_active' => true,
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'verify_ssl' => false,
            'hostname' => 'da.example.com',
        ]);
    }

    public function test_reseller_can_view_nodes_page(): void
    {
        $reseller = $this->reseller();
        $this->directAdminNode();

        $this->actingAs($reseller)
            ->get(route('reseller.nodes.index'))
            ->assertOk()
            ->assertSee('Connect DirectAdmin')
            ->assertSee('Infrastructure');
    }

    public function test_reseller_can_connect_directadmin_account(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USERS*' => Http::response(json_encode(['error' => '0', 'list' => ['user1']]), 200),
            '*/CMD_API_PACKAGES_USER*' => Http::response(json_encode(['error' => '0', 'list' => ['Bronze']]), 200),
            '*/CMD_API_SHOW_USER_USAGE*' => Http::response('quota=100&bandwidth=50', 200),
        ]);

        $reseller = $this->reseller();
        $node = $this->directAdminNode();

        $this->actingAs($reseller)
            ->post(route('reseller.nodes.connect'), [
                'reseller_node_id' => $node->id,
                'directadmin_username' => 'reseller_acme',
            ])
            ->assertRedirect(route('reseller.nodes.index', ['refresh' => 1]));

        $reseller->refresh();

        $this->assertSame('reseller_acme', $reseller->directadmin_username);
        $this->assertSame($node->id, $reseller->reseller_node_id);
        $this->assertNotEmpty($reseller->settings['directadmin_connected_at'] ?? null);
    }

    public function test_reseller_sees_dashboard_when_connected(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USERS*' => Http::response(json_encode(['error' => '0', 'list' => []]), 200),
            '*/CMD_API_PACKAGES_USER*' => Http::response(json_encode(['error' => '0', 'list' => ['Silver']]), 200),
        ]);

        $node = $this->directAdminNode();
        $reseller = $this->reseller();
        $reseller->update([
            'directadmin_username' => 'res_acme',
            'reseller_node_id' => $node->id,
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.nodes.index'))
            ->assertOk()
            ->assertSee('Connected')
            ->assertSee('res_acme')
            ->assertSee('DirectAdmin packages')
            ->assertDontSee('Connect DirectAdmin');
    }

    public function test_reseller_can_disconnect(): void
    {
        $node = $this->directAdminNode();
        $reseller = $this->reseller();
        $reseller->update([
            'directadmin_username' => 'res_acme',
            'reseller_node_id' => $node->id,
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.nodes.disconnect'))
            ->assertRedirect(route('reseller.nodes.index'));

        $reseller->refresh();

        $this->assertNull($reseller->directadmin_username);
        $this->assertNull($reseller->reseller_node_id);
    }

    public function test_connection_test_json_endpoint(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USERS*' => Http::response(json_encode(['error' => '0', 'list' => ['a', 'b']]), 200),
            '*/CMD_API_PACKAGES_USER*' => Http::response(json_encode(['error' => '0', 'list' => ['Gold']]), 200),
            '*/CMD_API_SHOW_USER_USAGE*' => Http::response('quota=0&bandwidth=0', 200),
        ]);

        $reseller = $this->reseller();
        $node = $this->directAdminNode();

        $this->actingAs($reseller)
            ->postJson(route('reseller.nodes.test'), [
                'reseller_node_id' => $node->id,
                'directadmin_username' => 'reseller1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('hosted_user_count', 2);
    }
}
