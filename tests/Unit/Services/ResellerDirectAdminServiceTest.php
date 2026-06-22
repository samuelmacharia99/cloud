<?php

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Models\User;
use App\Services\ResellerDirectAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerDirectAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspend_reseller_account_calls_directadmin_when_configured(): void
    {
        Http::fake([
            '*' => Http::response('error=0', 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'is_active' => true,
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'verify_ssl' => false,
        ]);

        $reseller = User::factory()->create([
            'is_reseller' => true,
            'directadmin_username' => 'res_acme',
            'reseller_node_id' => $node->id,
        ]);

        $this->assertTrue(app(ResellerDirectAdminService::class)->suspendResellerAccount($reseller));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'CMD_API_SELECT_USERS')
            && $request['select0'] === 'res_acme');
    }

    public function test_fetch_hosted_user_count_returns_null_without_username(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);

        $this->assertNull(app(ResellerDirectAdminService::class)->fetchHostedUserCount($reseller));
    }

    public function test_verify_binding_succeeds_when_reseller_exists_on_server(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USERS*' => Http::response(json_encode(['error' => '0', 'list' => []]), 200),
            '*/CMD_API_PACKAGES_USER*' => Http::response(json_encode(['error' => '0', 'list' => ['Basic']]), 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'is_active' => true,
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'verify_ssl' => false,
        ]);

        $result = app(ResellerDirectAdminService::class)->verifyBinding($node, 'res_acme', 'reseller-login-key');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['hosted_user_count']);
        $this->assertCount(1, $result['packages']);
    }

    public function test_resolve_connectable_node_includes_api_url_for_verification(): void
    {
        $node = Node::factory()->create([
            'type' => 'directadmin',
            'is_active' => true,
            'api_url' => 'https://da.example.com:2222',
            'verify_ssl' => false,
        ]);

        $resolved = app(ResellerDirectAdminService::class)->resolveConnectableNode($node->id);

        $this->assertNotNull($resolved);
        $this->assertSame('https://da.example.com:2222', $resolved->api_url);
    }
}
