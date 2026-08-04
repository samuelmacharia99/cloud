<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOverpaymentCreditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_does_not_auto_issue_overpayment_credit(): void
    {
        $customer = User::factory()->customer()->create();

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-OVER-AUTO',
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 1500,
            'currency' => 'KES',
            'payment_method' => PaymentMethod::Mpesa,
            'transaction_reference' => 'mpesa-over-1',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment->fresh(['invoice', 'user']));

        $this->assertTrue($payment->fresh()->isOverpayment());
        $this->assertEquals(500.0, $payment->fresh()->getOverpaymentAmount());
        $this->assertSame(0, Credit::where('payment_id', $payment->id)->count());
        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_admin_can_authorize_overpayment_credit(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-OVER-AUTH',
            'status' => 'paid',
            'due_date' => now()->addDays(7),
            'paid_date' => now(),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 1500,
            'currency' => 'KES',
            'payment_method' => PaymentMethod::Mpesa,
            'transaction_reference' => 'mpesa-over-2',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.payments.show', $payment))
            ->post(route('admin.payments.credit-overpayment', $payment))
            ->assertRedirect(route('admin.payments.show', $payment));

        $credit = Credit::where('payment_id', $payment->id)->where('source', 'overpayment')->first();
        $this->assertNotNull($credit);
        $this->assertEquals(500.0, (float) $credit->amount);

        $this->actingAs($admin)
            ->post(route('admin.payments.credit-overpayment', $payment))
            ->assertRedirect();

        $this->assertSame(1, Credit::where('payment_id', $payment->id)->count());
    }

    public function test_customer_cannot_authorize_overpayment_credit(): void
    {
        $customer = User::factory()->customer()->create();

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-OVER-CUST',
            'status' => 'paid',
            'due_date' => now()->addDays(7),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 1500,
            'currency' => 'KES',
            'payment_method' => PaymentMethod::Mpesa,
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $this->actingAs($customer)
            ->post(route('admin.payments.credit-overpayment', $payment))
            ->assertForbidden();

        $this->assertSame(0, Credit::where('payment_id', $payment->id)->count());
    }
}
