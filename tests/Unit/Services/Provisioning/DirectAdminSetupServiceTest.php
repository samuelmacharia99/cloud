<?php

namespace Tests\Unit\Services\Provisioning;

use App\Models\DirectAdminPackage;
use App\Models\Node;
use App\Models\Setting;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\DirectAdminSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectAdminSetupServiceTest extends TestCase
{
    use RefreshDatabase;
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

    public function test_auto_push_skipped_when_setting_disabled(): void
    {
        Setting::updateOrCreate(
            ['key' => 'directadmin_auto_push_package_limits'],
            ['value' => '0', 'description' => 'test'],
        );

        Http::fake();

        $package = new DirectAdminPackage([
            'name' => 'Bronze',
            'disk_quota' => 5,
        ]);

        app(DirectAdminSetupService::class)->ensurePackageOnServer(new DirectAdminService($this->createDirectAdminNode()), $package);

        Http::assertNothingSent();
        $this->assertFalse(app(DirectAdminSetupService::class)->autoPushPackageLimitsEnabled());
    }

    public function test_auto_push_runs_when_setting_enabled(): void
    {
        Setting::updateOrCreate(
            ['key' => 'directadmin_auto_push_package_limits'],
            ['value' => '1', 'description' => 'test'],
        );

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

        app(DirectAdminSetupService::class)->ensurePackageOnServer(new DirectAdminService($this->createDirectAdminNode()), $package);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'CMD_API_MANAGE_USER_PACKAGES'));
    }
}
