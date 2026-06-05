<?php

namespace Tests\Unit\Services\Provisioning;

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
            if (str_contains($request->url(), 'CMD_API_PACKAGES_RESELLER')) {
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
            return str_contains($request->url(), 'CMD_API_PACKAGES_RESELLER')
                && $request['user'] === 'reseller_acme';
        });
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
