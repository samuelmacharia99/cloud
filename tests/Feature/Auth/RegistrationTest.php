<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\RegistrationGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.min_submit_seconds' => 0]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('id="register-first-name"', false);
        $response->assertSee('id="register-last-name"', false);
        $response->assertSee('name="first_name"', false);
        $response->assertSee('data-form-version="2026-06-28-phone"', false);
        $response->assertSee('id="register-phone"', false);
        $response->assertDontSee('Use your first and last name', false);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'country' => 'KE',
            'email' => 'test@example.com',
            'phone' => '0712345678',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'agree' => '1',
            'registration_token' => app(RegistrationGuardService::class)->makeFormToken(),
        ], $overrides);
    }

    public function test_legacy_single_name_field_is_accepted_on_register(): void
    {
        Mail::fake();

        $payload = $this->registrationPayload(['email' => 'legacy-name@example.com']);
        unset($payload['first_name'], $payload['last_name']);
        $payload['name'] = 'Jane Doe';

        $response = $this->post('/register', $payload);

        $response->assertRedirect(route('verification.code.show'));

        $user = User::where('email', 'legacy-name@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Jane Doe', $user->name);
    }

    public function test_new_users_can_register(): void
    {
        Mail::fake();

        $response = $this->post('/register', $this->registrationPayload([
            'email' => 'test@example.com',
        ]));

        $response->assertRedirect(route('verification.code.show'));
        $this->assertGuest();

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('inactive', $user->status);
        $this->assertSame('KE', $user->country);
        $this->assertSame('254712345678', $user->phone);
    }

    public function test_signup_requires_all_critical_fields(): void
    {
        $response = $this->from('/register')->post('/register', [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'password' => '',
            'password_confirmation' => '',
            'agree' => '',
            'registration_token' => '',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'first_name',
            'country',
            'email',
            'phone',
            'password',
            'agree',
            'registration_token',
        ]);
    }

    public function test_registration_accepts_first_name_without_last_name(): void
    {
        Mail::fake();

        $response = $this->post('/register', $this->registrationPayload([
            'first_name' => 'Jane',
            'last_name' => '',
            'email' => 'jane-only@example.com',
        ]));

        $response->assertRedirect(route('verification.code.show'));

        $user = User::where('email', 'jane-only@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Jane', $user->name);
    }

    public function test_registration_password_generator_is_available_to_guests(): void
    {
        $response = $this->getJson('/register/generate-password?length=16');

        $response->assertOk();
        $response->assertJsonStructure(['password']);
        $this->assertGreaterThanOrEqual(16, strlen($response->json('password')));
    }
}
