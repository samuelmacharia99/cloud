<?php

namespace Tests\Feature\Auth;

use App\Mail\TwoFactorCodeMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TwoFactorEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlatformSms(): void
    {
        Setting::setValue('sms_enabled', '1');
        Setting::setValue('sms_api_token', 'test-token');
        Setting::setValue('sms_sender_id', 'TalksasaCloud');
    }

    private function seedSmtp(): void
    {
        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_user', 'mailer@example.com');
        Setting::setValue('smtp_password', 'secret');
        Setting::setValue('smtp_encryption', 'tls');
        Setting::setValue('mail_from_address', 'noreply@example.com');
        Setting::setValue('mail_from_name', 'Talksasa');
    }

    public function test_sends_2fa_code_by_email_and_sms_when_both_configured(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);
        $this->seedPlatformSms();
        $this->seedSmtp();

        $user = User::factory()->create([
            'phone' => '0712345678',
            'two_factor_enabled' => true,
        ]);

        $delivery = app(TwoFactorService::class)->sendCode($user);

        $this->assertTrue($delivery['email']);
        $this->assertTrue($delivery['sms']);
        $this->assertTrue(TwoFactorService::deliverySucceeded($delivery));
        Mail::assertSent(TwoFactorCodeMail::class);
        Http::assertSentCount(1);
        $this->assertNotNull($user->fresh()->two_factor_code);
    }

    public function test_sends_2fa_code_by_email_only_when_sms_unavailable(): void
    {
        Mail::fake();
        $this->seedSmtp();

        $user = User::factory()->create([
            'phone' => null,
            'two_factor_enabled' => true,
        ]);

        $delivery = app(TwoFactorService::class)->sendCode($user);

        $this->assertTrue($delivery['email']);
        $this->assertFalse($delivery['sms']);
        Mail::assertSent(TwoFactorCodeMail::class);
    }

    public function test_login_prompts_2fa_and_emails_code_without_phone(): void
    {
        Mail::fake();
        $this->seedSmtp();

        $user = User::factory()->create([
            'phone' => null,
            'two_factor_enabled' => true,
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('auth.two-factor.verify'));
        $this->assertGuest();
        Mail::assertSent(TwoFactorCodeMail::class);
        $this->assertTrue(session()->has('two_factor_user_id'));
        $this->assertSame(
            ['email' => true, 'sms' => false],
            session('two_factor_delivery')
        );
    }

    public function test_delivery_summary_lists_both_channels(): void
    {
        $this->assertSame(
            'your email and phone (SMS)',
            TwoFactorService::deliverySummary(['email' => true, 'sms' => true])
        );
        $this->assertSame(
            'your email',
            TwoFactorService::deliverySummary(['email' => true, 'sms' => false])
        );
    }
}
