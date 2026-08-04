<?php

namespace Tests\Feature\Reseller;

use App\Models\Node;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerDirectAdminPanelLoginTest extends TestCase
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
            'da_port' => 2222,
        ]);
    }

    public function test_reseller_can_open_directadmin_panel_login(): void
    {
        Http::fake([
            '*/CMD_API_LOGIN_KEYS' => Http::response('error=0&details=/CMD_LOGIN_URL?hash=reseller-sso', 200),
        ]);

        $node = $this->directAdminNode();
        $reseller = $this->reseller();
        $reseller->update([
            'directadmin_username' => 'willisoch',
            'reseller_node_id' => $node->id,
            'directadmin_login_key' => 'reseller-key',
        ]);

        $response = $this->actingAs($reseller)
            ->get(route('reseller.dashboard.directadmin.panel-login'));

        $response->assertRedirect();
        $this->assertStringContainsString('CMD_LOGIN_URL', $response->headers->get('Location'));
        $this->assertStringContainsString('hash=reseller-sso', $response->headers->get('Location'));
    }

    public function test_reseller_without_directadmin_binding_gets_error(): void
    {
        $reseller = $this->reseller();

        $this->actingAs($reseller)
            ->get(route('reseller.dashboard.directadmin.panel-login'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_customer_cannot_use_reseller_panel_login(): void
    {
        $customer = User::factory()->create(['is_reseller' => false]);

        $this->actingAs($customer)
            ->get(route('reseller.dashboard.directadmin.panel-login'))
            ->assertForbidden();
    }
}
