<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\RegistrationGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registration.min_submit_seconds' => 0]);
    }

    public function test_registration_creates_inactive_unverified_user(): void
    {
        Mail::fake();

        $token = app(RegistrationGuardService::class)->makeFormToken();

        $response = $this->post('/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'agree' => '1',
            'registration_token' => $token,
        ]);

        $response->assertRedirect(route('verification.code.show'));

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('inactive', $user->status);
    }

    public function test_honeypot_submission_does_not_create_user(): void
    {
        Mail::fake();

        $token = app(RegistrationGuardService::class)->makeFormToken();

        $response = $this->post('/register', [
            'name' => 'Jane Doe',
            'email' => 'bot@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'agree' => '1',
            'registration_token' => $token,
            'contact_website' => 'https://spam.test',
        ]);

        $response->assertRedirect(route('verification.code.show'));
        $this->assertNull(User::where('email', 'bot@example.com')->first());
    }

    public function test_disposable_email_is_rejected(): void
    {
        Mail::fake();

        $token = app(RegistrationGuardService::class)->makeFormToken();

        $response = $this->post('/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@yopmail.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'agree' => '1',
            'registration_token' => $token,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNull(User::where('email', 'jane@yopmail.com')->first());
    }

    public function test_unverified_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'status' => 'inactive',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
