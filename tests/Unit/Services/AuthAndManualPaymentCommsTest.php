<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationEvent;
use App\Mail\ManualPaymentRejectedMail;
use App\Mail\PasswordChangedMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthEmailService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthAndManualPaymentCommsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_from_address', 'noreply@example.com');
        Setting::setValue('notify_manual_payment_rejected', 'true');
        Setting::setValue('notify_password_changed', 'true');
    }

    public function test_password_reset_uses_platform_smtp_for_admin_customer(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'reset@example.com']);

        $sent = app(AuthEmailService::class)->sendPasswordReset($customer, 'test-token');

        $this->assertTrue($sent);
        Mail::assertQueued(\App\Mail\PasswordResetMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }

    public function test_notify_manual_payment_rejected_sends_customer_email(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'reject@example.com']);
        $invoice = Invoice::factory()->create(['user_id' => $customer->id, 'status' => 'unpaid']);
        $payment = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'manual',
            'status' => 'failed',
        ]);

        app(NotificationService::class)->notifyManualPaymentRejected($payment, 'Proof image unreadable');

        Mail::assertQueued(ManualPaymentRejectedMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }

    public function test_notify_password_changed_sends_customer_email(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'changed@example.com']);

        app(NotificationService::class)->notifyPasswordChanged($customer);

        Mail::assertQueued(PasswordChangedMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }
}
