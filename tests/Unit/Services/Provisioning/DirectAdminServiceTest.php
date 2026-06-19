<?php

namespace Tests\Unit\Services\Provisioning;

use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectAdminServiceTest extends TestCase
{
    private function createDirectAdminNode(): Node
    {
        $node = new Node([
            'name' => 'DA Test Node',
            'hostname' => 'da-test.example.com',
            'ip_address' => '10.0.0.10',
            'type' => 'directadmin',
            'status' => 'online',
            'api_url' => 'https://da.example.com:2222',
            'da_admin_username' => 'admin',
            'da_login_key' => 'secret',
            'da_port' => '2222',
            'verify_ssl' => false,
            'is_active' => true,
        ]);
        $node->id = 1;

        return $node;
    }

    private function createService(string $username = 'cust001'): Service
    {
        $user = User::make(['email' => 'customer@example.com']);
        $service = Service::make([
            'external_reference' => $username,
            'service_meta' => [],
        ]);
        $service->id = 42;
        $service->setRelation('user', $user);

        return $service;
    }

    public function test_create_hosting_account_rejects_api_error_body(): void
    {
        Http::fake([
            '*' => Http::response('error=1&text=User+already+exists', 200),
        ]);

        $result = (new DirectAdminService($this->createDirectAdminNode()))->createHostingAccount(
            $this->createService(),
            'cust001',
            'Str0ngPass!',
            'example.com',
            'Bronze'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', strtolower($result['message']));
    }

    public function test_suspend_account_uses_shared_http_client_settings(): void
    {
        Http::fake([
            '*' => Http::response('error=0&text=Suspended', 200),
        ]);

        $node = $this->createDirectAdminNode();
        $service = $this->createService();

        $this->assertTrue((new DirectAdminService($node))->suspendAccount($service));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_SELECT_USERS')
                && $request['suspend'] === 'Suspend'
                && $request['select0'] === 'cust001';
        });
    }

    public function test_terminate_account_calls_delete_endpoint(): void
    {
        Http::fake([
            '*' => Http::response('error=0&text=Deleted', 200),
        ]);

        $node = $this->createDirectAdminNode();
        $service = $this->createService();

        $this->assertTrue((new DirectAdminService($node))->terminateAccount($service));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_ACCOUNT_USER')
                && $request['action'] === 'delete'
                && $request['user'] === 'cust001';
        });
    }

    public function test_suspend_user_by_username_uses_select_users_endpoint(): void
    {
        Http::fake([
            '*' => Http::response('error=0&text=Suspended', 200),
        ]);

        $node = $this->createDirectAdminNode();

        $this->assertTrue((new DirectAdminService($node))->suspendUserByUsername('reseller1'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_SELECT_USERS')
                && $request['suspend'] === 'Suspend'
                && $request['select0'] === 'reseller1';
        });
    }

    public function test_count_users_owned_by_reseller_parses_json_list(): void
    {
        Http::fake([
            '*' => Http::response(json_encode([
                'error' => '0',
                'list' => ['user_a', 'user_b'],
            ]), 200),
        ]);

        $count = (new DirectAdminService($this->createDirectAdminNode()))
            ->countUsersOwnedByReseller('reseller1');

        $this->assertSame(2, $count);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_SHOW_USERS')
                && $request['reseller'] === 'reseller1';
        });
    }

    public function test_create_hosting_account_passes_reseller_when_provided(): void
    {
        Http::fake([
            '*' => Http::response('error=0&text=Created', 200),
        ]);

        $result = (new DirectAdminService($this->createDirectAdminNode()))->createHostingAccount(
            $this->createService('cust003'),
            'cust003',
            'Str0ngPass!',
            'example.com',
            'Bronze',
            'reseller_acme',
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_ACCOUNT_USER')
                && $request['action'] === 'create'
                && $request['reseller'] === 'reseller_acme';
        });
    }

    public function test_get_reseller_packages_lists_packages_for_reseller(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'CMD_API_PACKAGES_USER') && ! isset($request['package'])) {
                return Http::response(json_encode(['list' => ['Bronze', 'Gold']]), 200);
            }

            return Http::response(json_encode(['disk' => '1000M', 'bandwidth' => '10000M']), 200);
        });

        $packages = (new DirectAdminService($this->createDirectAdminNode()))
            ->getResellerPackages('reseller_acme');

        $this->assertCount(2, $packages);
        $this->assertSame('Bronze', $packages[0]['name']);
        $this->assertSame('Gold', $packages[1]['name']);

        Http::assertSent(function ($request) {
            $expectedAuth = 'Basic '.base64_encode('admin|reseller_acme:secret');

            return str_contains($request->url(), 'CMD_API_PACKAGES_USER')
                && ! isset($request['package'])
                && ($request->header('Authorization')[0] ?? '') === $expectedAuth;
        });
    }

    public function test_ensure_user_package_pushes_disk_and_bandwidth_limits(): void
    {
        Http::fake([
            '*' => Http::response('error=0&text=Saved', 200),
        ]);

        $package = new DirectAdminPackage([
            'name' => 'Bronze',
            'package_key' => 'bronze',
            'disk_quota' => 5,
            'bandwidth_quota' => 50,
            'num_domains' => 1,
            'num_ftp' => 2,
            'num_email_accounts' => 10,
            'num_databases' => 3,
            'num_subdomains' => 5,
            'features' => ['php' => true, 'ssl' => true],
        ]);

        $result = (new DirectAdminService($this->createDirectAdminNode()))->ensureUserPackage($package);

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'CMD_API_MANAGE_USER_PACKAGES')
                && $request['packagename'] === 'Bronze'
                && $request['add'] === 'Save'
                && (int) $request['quota'] === 5120
                && $request['uquota'] === 'OFF'
                && (int) $request['bandwidth'] === 51200
                && $request['ubandwidth'] === 'OFF'
                && (int) $request['vdomains'] === 1
                && (int) $request['mysql'] === 3;
        });
    }

    public function test_get_packages_reads_quota_field_for_disk(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'CMD_API_PACKAGES_USER') && ! isset($request['package'])) {
                return Http::response(json_encode(['list' => ['Starter']]), 200);
            }

            return Http::response(json_encode([
                'quota' => '2048M',
                'uquota' => 'OFF',
                'bandwidth' => '10240M',
                'ubandwidth' => 'OFF',
                'ftp' => '5',
                'uftp' => 'OFF',
                'mysql' => '3',
                'umysql' => 'OFF',
                'vdomains' => '3',
                'uvdomains' => 'OFF',
                'nsubdomains' => '10',
                'unsubdomains' => 'OFF',
                'nemails' => '25',
                'unemails' => 'OFF',
            ]), 200);
        });

        $packages = (new DirectAdminService($this->createDirectAdminNode()))->getPackages();

        $this->assertCount(1, $packages);
        $this->assertSame(2.0, $packages[0]['disk_quota']);
        $this->assertSame(10.0, $packages[0]['bandwidth_quota']);
        $this->assertSame(3, $packages[0]['num_domains']);
        $this->assertSame(25, $packages[0]['num_email_accounts']);
        $this->assertSame(10, $packages[0]['num_subdomains']);
    }

    public function test_get_packages_honors_unlimited_flags(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'CMD_API_PACKAGES_USER') && ! isset($request['package'])) {
                return Http::response(json_encode(['list' => ['UnlimitedPlan']]), 200);
            }

            return Http::response(json_encode([
                'uquota' => 'ON',
                'ubandwidth' => 'ON',
                'uvdomains' => 'ON',
                'unemails' => 'ON',
            ]), 200);
        });

        $packages = (new DirectAdminService($this->createDirectAdminNode()))->getPackages();

        $this->assertSame(-1.0, $packages[0]['disk_quota']);
        $this->assertSame(-1.0, $packages[0]['bandwidth_quota']);
        $this->assertSame(-1, $packages[0]['num_domains']);
        $this->assertSame(-1, $packages[0]['num_email_accounts']);
    }

    public function test_get_admin_reseller_packages_reads_reseller_package_fields(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'CMD_API_PACKAGES_RESELLER') && ! isset($request['package'])) {
                return Http::response(json_encode(['list' => ['ResellerGold']]), 200);
            }

            return Http::response(json_encode([
                'quota' => '10240',
                'bandwidth' => '51200',
                'vdomains' => '50',
                'ips' => '5',
                'nemails' => 'unlimited',
                'mysql' => '20',
                'nsubdomains' => '100',
                'ssl' => 'ON',
                'ssh' => 'OFF',
                'dnscontrol' => 'ON',
                'serverip' => 'ON',
            ]), 200);
        });

        $packages = (new DirectAdminService($this->createDirectAdminNode()))->getAdminResellerPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('ResellerGold', $packages[0]['name']);
        $this->assertSame(10.0, $packages[0]['disk_quota']);
        $this->assertSame(50.0, $packages[0]['bandwidth_quota']);
        $this->assertSame(50, $packages[0]['num_domains']);
        $this->assertSame(5, $packages[0]['num_ips']);
        $this->assertSame(-1, $packages[0]['num_email_accounts']);
        $this->assertTrue($packages[0]['features']['ssl']);
        $this->assertFalse($packages[0]['features']['ssh']);
    }

    public function test_empty_api_body_is_treated_as_failure_for_create(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $result = (new DirectAdminService($this->createDirectAdminNode()))->createHostingAccount(
            $this->createService('cust002'),
            'cust002',
            'Str0ngPass!',
            'example.com',
            'Bronze'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('empty response', strtolower($result['message']));
    }
}
