<?php

namespace Tests\Feature\Reseller;

use App\Models\User;
use App\Services\ResellerMailService;
use App\Services\ResellerSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResellerSmtpSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        return User::factory()->reseller()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function smtpPayload(array $overrides = []): array
    {
        return array_merge([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer@example.test',
            'smtp_password' => 'secret-password',
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'noreply@example.test',
            'smtp_from_name' => 'Example Hosting',
            'smtp_enabled' => '1',
        ], $overrides);
    }

    public function test_email_tab_shows_guidance_and_does_not_expose_saved_password(): void
    {
        $reseller = $this->createReseller();
        $reseller->update([
            'settings' => [
                'smtp' => [
                    'host' => 'smtp.example.test',
                    'port' => 587,
                    'username' => 'mailer@example.test',
                    'password' => 'super-secret-smtp-password',
                    'encryption' => 'tls',
                    'from_address' => 'noreply@example.test',
                    'from_name' => 'Example Hosting',
                    'enabled' => true,
                ],
            ],
        ]);

        $response = $this->actingAs($reseller)
            ->get(route('reseller.settings.index', ['tab' => 'email']));

        $response->assertOk();
        $response->assertSee('Configure SMTP to send email to', false);
        $response->assertSee('Leave blank to keep the existing password.', false);
        $response->assertDontSee('super-secret-smtp-password', false);
    }

    public function test_reseller_can_save_smtp_settings(): void
    {
        $reseller = $this->createReseller();

        $response = $this->actingAs($reseller)
            ->post(route('reseller.settings.smtp.update'), $this->smtpPayload());

        $response->assertRedirect(route('reseller.settings.index', ['tab' => 'email']));
        $response->assertSessionHas('success');

        $reseller->refresh();
        $smtp = $reseller->settings['smtp'];

        $this->assertSame('smtp.example.test', $smtp['host']);
        $this->assertSame('secret-password', $smtp['password']);
        $this->assertTrue($smtp['enabled']);
    }

    public function test_update_preserves_password_when_left_blank(): void
    {
        $reseller = $this->createReseller();

        app(ResellerSettingsService::class)->updateSmtpSettings($reseller, $this->smtpPayload());
        $reseller->refresh();

        $response = $this->actingAs($reseller)
            ->post(route('reseller.settings.smtp.update'), $this->smtpPayload([
                'smtp_password' => '',
                'smtp_from_name' => 'Updated Hosting Co',
            ]));

        $response->assertRedirect(route('reseller.settings.index', ['tab' => 'email']));
        $response->assertSessionHas('success');

        $reseller->refresh();
        $smtp = $reseller->settings['smtp'];

        $this->assertSame('secret-password', $smtp['password']);
        $this->assertSame('Updated Hosting Co', $smtp['from_name']);
    }

    public function test_initial_setup_requires_password(): void
    {
        $reseller = $this->createReseller();

        $response = $this->actingAs($reseller)
            ->post(route('reseller.settings.smtp.update'), $this->smtpPayload([
                'smtp_password' => '',
            ]));

        $response->assertSessionHasErrors('smtp_password');
    }

    public function test_test_smtp_requires_enabled_settings(): void
    {
        $reseller = $this->createReseller();

        app(ResellerSettingsService::class)->updateSmtpSettings($reseller, $this->smtpPayload([
            'smtp_enabled' => '0',
        ]));

        $response = $this->actingAs($reseller)
            ->post(route('reseller.settings.smtp.test'), [
                'test_email' => 'admin@example.test',
            ]);

        $response->assertRedirect(route('reseller.settings.index', ['tab' => 'email']));
        $response->assertSessionHas('error', 'Enable SMTP and save your settings before sending a test email.');
    }

    public function test_test_smtp_sends_when_configured(): void
    {
        Mail::fake();

        $reseller = $this->createReseller();
        app(ResellerSettingsService::class)->updateSmtpSettings($reseller, $this->smtpPayload());

        $this->mock(ResellerMailService::class, function ($mock): void {
            $mock->shouldReceive('resellerSmtpEnabled')->andReturn(true);
            $mock->shouldReceive('sendTest')->once();
        });

        $response = $this->actingAs($reseller)
            ->post(route('reseller.settings.smtp.test'), [
                'test_email' => 'admin@example.test',
            ]);

        $response->assertRedirect(route('reseller.settings.index', ['tab' => 'email']));
        $response->assertSessionHas('success');
    }
}
