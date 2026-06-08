<?php

namespace Tests\Feature\Auth;

use App\Mail\VerificationCodeMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationSmsFallbackTest extends TestCase
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

    public function test_sends_sms_when_email_not_configured(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);
        $this->seedPlatformSms();

        $user = User::factory()->unverified()->create([
            'phone' => '0712345678',
        ]);

        $delivery = app(EmailVerificationService::class)->sendVerificationCode($user);

        $this->assertFalse($delivery['email']);
        $this->assertTrue($delivery['sms']);
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $user->id]);
        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_sends_both_email_and_sms_when_both_configured(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);
        $this->seedPlatformSms();
        $this->seedSmtp();

        $user = User::factory()->unverified()->create([
            'phone' => '0712345678',
        ]);

        $delivery = app(EmailVerificationService::class)->sendVerificationCode($user);

        $this->assertTrue($delivery['email']);
        $this->assertTrue($delivery['sms']);
        Mail::assertSent(VerificationCodeMail::class);
    }

    public function test_login_uses_sms_fallback_for_unverified_admin_customer(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);
        $this->seedPlatformSms();

        $user = User::factory()->unverified()->create([
            'phone' => '0712345678',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('verification.code.show', ['email' => $user->email]));
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $user->id]);
        Http::assertSentCount(1);
    }

    public function test_fails_when_neither_email_nor_sms_can_deliver(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create([
            'phone' => null,
        ]);

        $this->expectException(\RuntimeException::class);

        app(EmailVerificationService::class)->sendVerificationCode($user);
    }

    public function test_email_succeeds_when_sms_fails(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'error', 'message' => 'fail'], 422),
        ]);
        $this->seedPlatformSms();
        $this->seedSmtp();

        $user = User::factory()->unverified()->create([
            'phone' => '0712345678',
        ]);

        $delivery = app(EmailVerificationService::class)->sendVerificationCode($user);

        $this->assertTrue($delivery['email']);
        $this->assertFalse($delivery['sms']);
        Mail::assertSent(VerificationCodeMail::class);
    }
}
