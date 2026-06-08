<?php

namespace Tests\Feature\Auth;

use App\Mail\VerificationCodeMail;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResellerEmailVerificationSendTest extends TestCase
{
    use RefreshDatabase;

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

    private function createUnverifiedReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 50,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->unverified()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
            'status' => 'active',
        ]);
    }

    public function test_profile_resend_sends_verification_code_synchronously(): void
    {
        Mail::fake();
        $this->seedSmtp();

        $reseller = $this->createUnverifiedReseller();

        $response = $this->actingAs($reseller)
            ->post(route('verification.send'));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'verification-link-sent');

        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use ($reseller) {
            return $mail->hasTo($reseller->email);
        });
    }

    private function seedPlatformSms(): void
    {
        Setting::setValue('sms_enabled', '1');
        Setting::setValue('sms_api_token', 'test-token');
        Setting::setValue('sms_sender_id', 'TalksasaCloud');
    }

    public function test_profile_resend_sends_via_sms_when_smtp_missing(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);
        $this->seedPlatformSms();

        $reseller = $this->createUnverifiedReseller();
        $reseller->update(['phone' => '0712345678']);

        $response = $this->actingAs($reseller)
            ->post(route('verification.send'));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'verification-link-sent');
        Mail::assertNothingSent();
    }

    public function test_profile_resend_shows_error_when_no_delivery_channel(): void
    {
        Mail::fake();

        $reseller = $this->createUnverifiedReseller();
        $reseller->update(['phone' => null]);

        $response = $this->actingAs($reseller)
            ->post(route('verification.send'));

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }
}
