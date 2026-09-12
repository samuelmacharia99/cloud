<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\PlatformAppsDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformAppsSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_three_platform_hostname_settings_save_and_the_dns_token_is_stored_encrypted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson(route('admin.settings.update'), [
            'settings' => [
                PlatformAppsDomainService::SETTING_ZONE => 'apps.example.com',
                PlatformAppsDomainService::SETTING_ZONE_ID => 'zone-abc',
                PlatformAppsDomainService::SETTING_DNS_TOKEN => 'dns-secret-token',
            ],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('apps.example.com', Setting::getValue(PlatformAppsDomainService::SETTING_ZONE));
        $this->assertSame('zone-abc', Setting::getValue(PlatformAppsDomainService::SETTING_ZONE_ID));
        $this->assertSame('dns-secret-token', Setting::getValue(PlatformAppsDomainService::SETTING_DNS_TOKEN));
        $this->assertStringNotContainsString(
            'dns-secret-token',
            (string) DB::table('settings')->where('key', PlatformAppsDomainService::SETTING_DNS_TOKEN)->value('value')
        );

        // A blank token on a later save keeps the current one, as for every other secret.
        $this->actingAs($admin)->postJson(route('admin.settings.update'), [
            'settings' => [PlatformAppsDomainService::SETTING_DNS_TOKEN => ''],
        ])->assertOk();
        $this->assertSame('dns-secret-token', Setting::getValue(PlatformAppsDomainService::SETTING_DNS_TOKEN));
    }
}
