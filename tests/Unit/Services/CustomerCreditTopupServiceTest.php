<?php

namespace Tests\Unit\Services;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerCreditTopupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerCreditTopupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_topup_payment_creates_purchase_credit(): void
    {
        $customer = User::factory()->create();

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'CREDIT-TEST001',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 500,
            'tax' => 0,
            'total' => 500,
        ]);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'credit_topup',
            'transaction_reference' => 'TEST-CREDIT-001',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $service = app(CustomerCreditTopupService::class);
        $credit = $service->processTopupPayment($payment);

        $this->assertInstanceOf(Credit::class, $credit);
        $this->assertSame('purchase', $credit->source);
        $this->assertSame(500.0, (float) $credit->amount);
        $this->assertSame($payment->id, $credit->payment_id);
        $this->assertSame('active', $credit->status);
        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_process_topup_payment_is_idempotent(): void
    {
        $customer = User::factory()->create();

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'CREDIT-TEST002',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 250,
            'tax' => 0,
            'total' => 250,
        ]);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 250,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'credit_topup',
            'transaction_reference' => 'TEST-CREDIT-002',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $service = app(CustomerCreditTopupService::class);
        $first = $service->processTopupPayment($payment);
        $second = $service->processTopupPayment($payment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Credit::where('payment_id', $payment->id)->count());
    }

    public function test_create_topup_invoice_includes_line_item(): void
    {
        $customer = User::factory()->create();
        $service = app(CustomerCreditTopupService::class);

        $invoice = $service->createTopupInvoice($customer, 1000);

        $this->assertStringStartsWith('CREDIT-', $invoice->invoice_number);
        $this->assertSame(1000.0, (float) $invoice->total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Account credit top-up', $invoice->items->first()->description);
    }
}
