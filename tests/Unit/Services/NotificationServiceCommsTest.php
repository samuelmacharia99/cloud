<?php

namespace Tests\Unit\Services;

use App\Mail\PaymentFailedMail;
use App\Mail\ServiceProvisionFailedMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceCommsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_from_address', 'noreply@example.com');
        Setting::setValue('notify_payment_failed', 'true');
        Setting::setValue('notify_service_provision_failed', 'true');
    }

    public function test_notify_payment_failed_sends_customer_email(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'payfail@example.com']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 100,
        ]);
        $payment = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'status' => 'failed',
            'amount' => 100,
            'payment_method' => 'mpesa',
        ]);

        app(NotificationService::class)->notifyPaymentFailed($payment, 'User cancelled M-Pesa prompt');

        Mail::assertQueued(PaymentFailedMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }

    public function test_notify_service_provision_failed_sends_customer_email(): void
    {
        Mail::fake();

        $customer = User::factory()->customer()->create(['email' => 'provision@example.com']);
        $service = Service::factory()->for($customer)->create([
            'status' => 'failed',
            'name' => 'Test Hosting',
        ]);

        app(NotificationService::class)->notifyServiceProvisionFailed($service, 'DirectAdmin API timeout');

        Mail::assertQueued(ServiceProvisionFailedMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }
}
