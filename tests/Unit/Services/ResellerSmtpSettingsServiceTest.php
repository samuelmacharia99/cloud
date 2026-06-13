<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\ResellerSettingsService;
use Tests\TestCase;

class ResellerSmtpSettingsServiceTest extends TestCase
{
    public function test_display_settings_hide_password_and_flag_when_configured(): void
    {
        $user = new User([
            'settings' => [
                'smtp' => [
                    'host' => 'smtp.example.test',
                    'port' => 587,
                    'username' => 'mailer@example.test',
                    'password' => 'super-secret',
                    'encryption' => 'tls',
                    'from_address' => 'noreply@example.test',
                    'from_name' => 'Example',
                    'enabled' => true,
                ],
            ],
        ]);

        $display = app(ResellerSettingsService::class)->getSmtpSettingsForDisplay($user);

        $this->assertArrayNotHasKey('password', $display);
        $this->assertTrue($display['password_configured']);
        $this->assertSame('smtp.example.test', $display['host']);
    }

    public function test_smtp_password_configured_returns_false_when_missing(): void
    {
        $user = new User([
            'settings' => [
                'smtp' => [
                    'host' => 'smtp.example.test',
                    'password' => '',
                ],
            ],
        ]);

        $this->assertFalse(app(ResellerSettingsService::class)->smtpPasswordConfigured($user));
    }
}
