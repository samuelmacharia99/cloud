<?php

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectAdminCustomerPanelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_one_time_login_url_builds_panel_redirect(): void
    {
        Http::fake([
            '*/CMD_API_LOGIN_KEYS' => Http::response('error=0&key=abc123login', 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'hostname' => 'da.example.com',
            'da_port' => 2222,
            'api_url' => 'https://da.example.com:2222',
            'da_login_key' => 'secret',
        ]);

        $api = DirectAdminCustomerPanelApi::forServiceNode($node);
        $result = $api->createOneTimeLoginUrl('siteuser');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('siteuser', $result['url']);
        $this->assertStringContainsString('abc123login', $result['url']);
    }

    public function test_get_dashboard_normalizes_usage_payload(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USER_CONFIG*' => Http::response(json_encode([
                'error' => '0',
                'domain' => 'example.com',
                'package' => 'Bronze',
                'quota' => '1024',
                'bandwidth' => 'unlimited',
            ]), 200),
            '*/CMD_API_USER_STATS*' => Http::response(json_encode([
                'error' => '0',
                'quota_used' => '256',
                'bandwidth_used' => '128',
                'email' => '2',
                'mysql' => '1',
            ]), 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'hostname' => 'da.example.com',
            'api_url' => 'https://da.example.com:2222',
            'da_login_key' => 'secret',
        ]);

        $api = DirectAdminCustomerPanelApi::forServiceNode($node);
        $result = $api->getDashboard('siteuser', 'example.com');

        $this->assertTrue($result['success']);
        $this->assertSame('example.com', $result['data']['domain']);
        $this->assertSame('Bronze', $result['data']['package']);
        $this->assertSame(256.0, $result['data']['disk']['used_mb']);
        $this->assertNull($result['data']['bandwidth']['limit_mb']);
    }
}
