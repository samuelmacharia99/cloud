<?php

namespace Tests\Unit\Services\PaymentGateway;

use App\Enums\PaymentStatus;
use App\Mail\PaymentFailedMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentGateway\OnlinePaymentFailureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnlinePaymentFailureServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_from_address', 'noreply@example.com');
        Setting::setValue('notify_payment_failed', 'true');
    }

    public function test_stripe_cancel_marks_pending_payment_failed_and_notifies_customer(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'stripe-fail@example.com']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);
        $payment = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'stripe',
            'status' => PaymentStatus::Pending,
            'transaction_reference' => 'cs_test_123',
        ]);

        app(OnlinePaymentFailureService::class)->recordAndNotify(
            $invoice,
            'stripe',
            'Stripe checkout was cancelled.',
            'cs_test_123',
        );

        $payment->refresh();
        $this->assertTrue($payment->isFailed());

        Mail::assertQueued(PaymentFailedMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }

    public function test_paypal_failure_by_reference_does_not_notify_completed_payments(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'paypal-ok@example.com']);
        $invoice = Invoice::factory()->create(['user_id' => $customer->id]);
        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'paypal',
            'status' => PaymentStatus::Completed,
            'transaction_reference' => 'ORDER123',
            'paid_at' => now(),
        ]);

        app(OnlinePaymentFailureService::class)->recordAndNotifyByReference(
            'ORDER123',
            'PayPal order was voided before capture.',
        );

        Mail::assertNothingQueued();
    }
}
