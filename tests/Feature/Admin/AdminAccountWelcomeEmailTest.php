<?php

namespace Tests\Feature\Admin;

use App\Mail\AccountWelcomeMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminAccountWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('mail_from_address', 'noreply@example.com');
        Setting::setValue('mail_from_name', 'Talksasa Cloud');
    }

    public function test_admin_can_send_welcome_email_when_creating_customer(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_reseller' => false])->save();

        $response = $this->actingAs($admin)->post(route('admin.customers.store'), [
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'status' => 'active',
            'send_welcome_email' => '1',
        ]);

        $response->assertRedirect(route('admin.customers.index'));
        $response->assertSessionHas('success');

        Mail::assertSent(AccountWelcomeMail::class, function (AccountWelcomeMail $mail) {
            return $mail->hasTo('jane@example.com')
                && $mail->plainPassword === 'SecurePass1!'
                && $mail->accountType === 'customer';
        });
    }

    public function test_admin_does_not_send_welcome_email_when_checkbox_unchecked(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_reseller' => false])->save();

        $this->actingAs($admin)->post(route('admin.customers.store'), [
            'name' => 'John Customer',
            'email' => 'john@example.com',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'status' => 'active',
        ])->assertRedirect(route('admin.customers.index'));

        Mail::assertNothingSent();
    }

    public function test_admin_can_send_welcome_email_when_creating_reseller(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'is_reseller' => false])->save();

        $response = $this->actingAs($admin)->post(route('admin.resellers.store'), [
            'name' => 'Reseller One',
            'email' => 'reseller@example.com',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'send_welcome_email' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $reseller = User::where('email', 'reseller@example.com')->first();
        $this->assertNotNull($reseller);
        $this->assertTrue($reseller->is_reseller);

        $response->assertRedirect(route('admin.resellers.show', $reseller));
        $response->assertSessionHas('success');

        Mail::assertSent(AccountWelcomeMail::class, function (AccountWelcomeMail $mail) {
            return $mail->hasTo('reseller@example.com')
                && $mail->plainPassword === 'SecurePass1!'
                && $mail->accountType === 'reseller';
        });
    }
}
