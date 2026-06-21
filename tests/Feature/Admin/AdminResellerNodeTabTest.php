<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminResellerNodeTabTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

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

    public function test_admin_reseller_show_includes_node_tab(): void
    {
        $reseller = $this->reseller();

        $this->actingAs($this->adminUser())
            ->get(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'node']))
            ->assertOk()
            ->assertSee('DirectAdmin node')
            ->assertSee('Link DirectAdmin account');
    }

    public function test_admin_can_connect_reseller_directadmin(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USERS*' => Http::response(json_encode(['error' => '0', 'list' => []]), 200),
            '*/CMD_API_PACKAGES_USER*' => Http::response(json_encode(['error' => '0', 'list' => ['Bronze']]), 200),
        ]);

        $reseller = $this->reseller();
        $node = $this->directAdminNode();

        $this->actingAs($this->adminUser())
            ->post(route('admin.resellers.directadmin.connect', $reseller), [
                'reseller_node_id' => $node->id,
                'directadmin_username' => 'reseller_acme',
            ])
            ->assertRedirect(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'node', 'refresh_node' => 1]));

        $reseller->refresh();

        $this->assertSame('reseller_acme', $reseller->directadmin_username);
        $this->assertSame($node->id, $reseller->reseller_node_id);
    }

    public function test_admin_can_disconnect_reseller_directadmin(): void
    {
        $node = $this->directAdminNode();
        $reseller = $this->reseller();
        $reseller->update([
            'directadmin_username' => 'res_acme',
            'reseller_node_id' => $node->id,
        ]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.resellers.directadmin.disconnect', $reseller))
            ->assertRedirect(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'node']));

        $reseller->refresh();

        $this->assertNull($reseller->directadmin_username);
    }

    public function test_reseller_cannot_access_admin_directadmin_connect(): void
    {
        $reseller = $this->reseller();
        $node = $this->directAdminNode();

        $this->actingAs($reseller)
            ->post(route('admin.resellers.directadmin.connect', $reseller), [
                'reseller_node_id' => $node->id,
                'directadmin_username' => 'hacker',
            ])
            ->assertForbidden();
    }
}
