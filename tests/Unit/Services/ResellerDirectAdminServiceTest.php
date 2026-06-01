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
}
