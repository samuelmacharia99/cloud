<?php

namespace Tests\Feature\Admin;

use App\Models\Email;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailResendTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_resend_failed_email(): void
    {
        Setting::setValue('smtp_host', 'smtp.test.local');

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $email = Email::create([
            'recipient' => 'customer@example.com',
            'subject' => 'Welcome to Talksasa',
            'body' => 'Your account is ready.',
            'status' => 'failed',
            'response' => 'Connection timed out',
            'sent_by' => $admin->id,
            'created_at' => now(),
        ]);

        $this->mock(EmailDeliveryService::class, function ($mock) use ($email) {
            $mock->shouldReceive('resendLoggedEmail')
                ->once()
                ->with(\Mockery::on(fn ($passed) => $passed->id === $email->id));
        });

        $response = $this->actingAs($admin)->post(route('admin.emails.resend', $email));

        $response->assertRedirect(route('admin.emails.show', $email));
        $response->assertSessionHas('success');
    }

    public function test_admin_cannot_resend_sent_email(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $email = Email::create([
            'recipient' => 'customer@example.com',
            'subject' => 'Invoice ready',
            'body' => 'Please pay your invoice.',
            'status' => 'sent',
            'sent_by' => $admin->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.emails.resend', $email));

        $response->assertForbidden();
    }
}
