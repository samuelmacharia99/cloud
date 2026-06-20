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
            '*/CMD_API_LOGIN_KEYS' => Http::response('error=0&details=/CMD_LOGIN_URL?hash=abc123', 200),
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
        $this->assertStringContainsString('CMD_LOGIN_URL', $result['url']);
        $this->assertStringContainsString('hash=abc123', $result['url']);
    }

    public function test_get_dashboard_normalizes_usage_payload(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_USER_CONFIG*' => Http::response(json_encode([
                'error' => '0',
                'domain' => 'example.com',
                'package' => 'Bronze',
                'quota' => '1024',
                'bandwidth' => '5120',
                'mysql' => '5',
                'umysql' => 'OFF',
            ]), 200),
            '*/CMD_API_SHOW_USER_USAGE*' => Http::response(json_encode([
                'error' => '0',
                'quota' => '256',
                'bandwidth' => '128',
                'mysql' => '1',
                'nemails' => '2',
            ]), 200),
            '*/CMD_API_DATABASES*' => Http::response(json_encode([
                'error' => '0',
                'list' => ['user_db'],
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
        $this->assertSame(5120.0, $result['data']['bandwidth']['limit_mb']);
        $this->assertSame(128.0, $result['data']['bandwidth']['used_mb']);
        $this->assertSame(1, $result['data']['counts']['database']);
        $this->assertSame(5, $result['data']['counts']['database_limit']);
        $this->assertSame(['user_db'], $result['data']['databases']);
    }

    public function test_database_used_count_does_not_fallback_to_package_limit_when_stats_omit_mysql(): void
    {
        Http::fake([
            '*/CMD_API_DATABASES*' => Http::response(json_encode([
                'error' => '0',
            ]), 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'hostname' => 'da.example.com',
            'api_url' => 'https://da.example.com:2222',
            'da_login_key' => 'secret',
        ]);

        $api = DirectAdminCustomerPanelApi::forServiceNode($node);

        $this->assertSame(0, $api->resolveDatabaseUsedCount('siteuser', []));
    }

    public function test_list_email_accounts_handles_indexed_list_keys(): void
    {
        Http::fake([
            '*/CMD_API_POP*' => Http::response(json_encode([
                'error' => '0',
                'list0' => 'info',
                'list1' => 'sales',
            ]), 200),
        ]);

        $node = Node::factory()->create([
            'type' => 'directadmin',
            'hostname' => 'da.example.com',
            'api_url' => 'https://da.example.com:2222',
            'da_login_key' => 'secret',
        ]);

        $api = DirectAdminCustomerPanelApi::forServiceNode($node);
        $result = $api->listEmailAccounts('siteuser', 'example.com');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('info@example.com', $result['data'][0]['email']);
        $this->assertSame('sales@example.com', $result['data'][1]['email']);
    }

    public function test_normalize_subdomain_label_strips_domain_suffix(): void
    {
        $node = Node::factory()->create([
            'type' => 'directadmin',
            'hostname' => 'da.example.com',
            'api_url' => 'https://da.example.com:2222',
            'da_login_key' => 'secret',
        ]);

        $api = DirectAdminCustomerPanelApi::forServiceNode($node);

        $this->assertSame('blog', $api->normalizeSubdomainLabel('blog.example.com', 'example.com'));
        $this->assertSame('blog', $api->normalizeSubdomainLabel('blog', 'example.com'));
    }
}
