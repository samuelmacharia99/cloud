<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Services\RegistrationGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PlatformRegistrationPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.min_submit_seconds' => 0]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Customer',
            'country' => 'KE',
            'email' => 'jane-platform@example.com',
            'phone' => '0712345678',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'agree' => '1',
            'registration_token' => app(RegistrationGuardService::class)->makeFormToken(),
        ], $overrides);
    }

    public function test_platform_register_form_shows_phone_field(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('id="register-phone"', false);
        $response->assertSee('name="phone"', false);
        $response->assertSee('email and SMS', false);
    }

    public function test_platform_registration_requires_phone(): void
    {
        Mail::fake();

        $response = $this->from('/register')->post('/register', $this->registrationPayload([
            'phone' => '',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors(['phone']);
    }

    public function test_platform_registration_rejects_invalid_phone(): void
    {
        Mail::fake();

        $response = $this->from('/register')->post('/register', $this->registrationPayload([
            'phone' => '12345',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors(['phone']);
    }

    public function test_platform_registration_stores_phone_and_sends_sms_verification(): void
    {
        Mail::fake();
        Http::fake([
            'bulksms.talksasa.com/*' => Http::response(['status' => 'accepted'], 202),
        ]);

        Setting::setValue('sms_enabled', '1');
        Setting::setValue('sms_api_token', 'test-token');
        Setting::setValue('sms_sender_id', 'TalksasaCloud');

        $response = $this->post('/register', $this->registrationPayload([
            'email' => 'sms-user@example.com',
        ]));

        $response->assertRedirect(route('verification.code.show'));

        $user = User::where('email', 'sms-user@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('254712345678', $user->phone);
        $this->assertNull($user->reseller_id);
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $user->id]);
        Http::assertSentCount(1);
    }

    public function test_reseller_invite_registration_does_not_show_phone_field(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);

        URL::forceRootUrl('http://localhost');

        $url = URL::temporarySignedRoute('register', now()->addHour(), [
            'reseller' => $reseller->id,
        ]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertDontSee('id="register-phone"', false);
        $response->assertSee('email you a verification code', false);
    }

    public function test_reseller_invite_registration_does_not_require_phone(): void
    {
        Mail::fake();

        $reseller = User::factory()->create(['is_reseller' => true]);

        URL::forceRootUrl('http://localhost');

        $registerUrl = URL::temporarySignedRoute('register', now()->addHour(), [
            'reseller' => $reseller->id,
        ]);

        $this->get($registerUrl);

        $response = $this->post('/register', [
            'first_name' => 'Reseller',
            'last_name' => 'Customer',
            'country' => 'KE',
            'email' => 'reseller-customer@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'agree' => '1',
            'registration_token' => app(RegistrationGuardService::class)->makeFormToken(),
        ]);

        $response->assertRedirect(route('verification.code.show'));

        $user = User::where('email', 'reseller-customer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($reseller->id, $user->reseller_id);
        $this->assertNull($user->phone);
    }
}
