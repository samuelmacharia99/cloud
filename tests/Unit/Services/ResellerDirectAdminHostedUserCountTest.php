<?php

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Models\User;
use App\Services\ResellerDirectAdminService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerDirectAdminHostedUserCountTest extends TestCase
{
    public function test_fetch_hosted_user_count_on_node_uses_specified_node(): void
    {
        Http::fake([
            '*' => Http::response(json_encode([
                'error' => '0',
                'list' => ['user_a', 'user_b', 'user_c'],
            ]), 200),
        ]);

        $node = new Node([
            'type' => 'directadmin',
            'is_active' => true,
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'verify_ssl' => false,
        ]);
        $node->id = 1;

        $reseller = new User([
            'is_reseller' => true,
            'directadmin_username' => 'res_acme',
        ]);

        $count = app(ResellerDirectAdminService::class)->fetchHostedUserCountOnNode($reseller, $node);

        $this->assertSame(3, $count);
    }

    public function test_fetch_hosted_user_count_on_node_returns_null_without_username(): void
    {
        $node = new Node(['type' => 'directadmin', 'api_url' => 'https://da.example.com:2222']);
        $reseller = new User(['is_reseller' => true]);

        $this->assertNull(app(ResellerDirectAdminService::class)->fetchHostedUserCountOnNode($reseller, $node));
    }

    public function test_fetch_total_hosted_disk_mb_sums_all_da_users_on_node(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'CMD_API_SHOW_USERS')) {
                return Http::response(json_encode([
                    'error' => '0',
                    'list' => ['site_a'],
                ]), 200);
            }

            if (str_contains($request->url(), 'CMD_API_SHOW_USER_CONFIG')) {
                return Http::response('error=0&quota=1024', 200);
            }

            if (str_contains($request->url(), 'CMD_API_USER_STATS')) {
                return Http::response('error=0&quota_used=1024', 200);
            }

            return Http::response('error=1', 200);
        });

        $node = new Node([
            'type' => 'directadmin',
            'is_active' => true,
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'verify_ssl' => false,
        ]);
        $node->id = 1;

        $reseller = new User([
            'is_reseller' => true,
            'directadmin_username' => 'res_acme',
            'reseller_node_id' => 1,
        ]);

        $mb = app(ResellerDirectAdminService::class)->fetchTotalHostedDiskMbOnNode($reseller, $node);

        $this->assertSame(1024.0, $mb);
    }
}
