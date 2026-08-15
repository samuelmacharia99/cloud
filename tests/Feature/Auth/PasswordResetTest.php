<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Password1!';

    private function seedPlatformSmtp(): void
    {
        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_user', 'mailer@example.com');
        Setting::setValue('smtp_password', 'secret');
        Setting::setValue('smtp_encryption', 'tls');
        Setting::setValue('mail_from_address', 'noreply@example.com');
        Setting::setValue('mail_from_name', 'Talksasa');
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Mail::fake();
        $this->seedPlatformSmtp();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo($user->email));
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Mail::fake();
        $this->seedPlatformSmtp();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            $token = null;
            if (preg_match('#/reset-password/([^?]+)#', $mail->resetUrl, $matches)) {
                $token = $matches[1];
            }

            $this->assertNotNull($token);

            $response = $this->get('/reset-password/'.$token);
            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Mail::fake();
        $this->seedPlatformSmtp();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            $token = null;
            if (preg_match('#/reset-password/([^?]+)#', $mail->resetUrl, $matches)) {
                $token = $matches[1];
            }

            $this->assertNotNull($token);

            $response = $this->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

            return true;
        });
    }
}
