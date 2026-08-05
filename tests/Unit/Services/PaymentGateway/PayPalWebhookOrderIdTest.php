<?php

namespace Tests\Unit\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentGateway\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayPalWebhookOrderIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_completed_resolves_order_id_from_supplementary_data(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 50,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'paypal',
            'transaction_reference' => 'ORDER-ABC-123',
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'USD',
        ]);

        $result = app(PayPalService::class)->handleWebhook([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAPTURE-XYZ-999',
                'status' => 'COMPLETED',
                'custom_id' => (string) $invoice->id,
                'amount' => ['value' => '50.00', 'currency_code' => 'USD'],
                'supplementary_data' => [
                    'related_ids' => [
                        'order_id' => 'ORDER-ABC-123',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($payment->id, $result['payment_id']);

        $payment->refresh();
        $this->assertSame('completed', $payment->status->value);
        $this->assertSame('ORDER-ABC-123', $payment->transaction_reference);

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseMissing('payments', [
            'transaction_reference' => 'CAPTURE-XYZ-999',
        ]);
    }

    public function test_capture_completed_falls_back_to_pending_payment_by_invoice_custom_id(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 25,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'paypal',
            'transaction_reference' => 'ORDER-FALLBACK-1',
            'status' => 'pending',
            'amount' => 25,
            'currency' => 'USD',
        ]);

        $result = app(PayPalService::class)->handleWebhook([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAPTURE-NO-ORDER-LINK',
                'status' => 'COMPLETED',
                'custom_id' => (string) $invoice->id,
                'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($payment->id, $result['payment_id']);

        $payment->refresh();
        $this->assertSame('completed', $payment->status->value);
        $this->assertSame('ORDER-FALLBACK-1', $payment->transaction_reference);
        $this->assertDatabaseMissing('payments', [
            'transaction_reference' => 'CAPTURE-NO-ORDER-LINK',
        ]);
    }

    public function test_checkout_order_completed_uses_resource_id_as_order_id(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 40,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'paypal',
            'transaction_reference' => 'ORDER-FROM-CHECKOUT',
            'status' => 'pending',
            'amount' => 40,
            'currency' => 'USD',
        ]);

        $result = app(PayPalService::class)->handleWebhook([
            'event_type' => 'CHECKOUT.ORDER.COMPLETED',
            'resource' => [
                'id' => 'ORDER-FROM-CHECKOUT',
                'status' => 'COMPLETED',
                'custom_id' => (string) $invoice->id,
                'amount' => ['value' => '40.00', 'currency_code' => 'USD'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($payment->id, $result['payment_id']);
        $payment->refresh();
        $this->assertSame('completed', $payment->status->value);
    }

    public function test_capture_without_order_id_or_matching_payment_does_not_key_by_capture_id(): void
    {
        $result = app(PayPalService::class)->handleWebhook([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAPTURE-ORPHAN',
                'status' => 'COMPLETED',
                'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('No order ID', $result['message']);
        $this->assertDatabaseMissing('payments', [
            'transaction_reference' => 'CAPTURE-ORPHAN',
        ]);
    }
}
