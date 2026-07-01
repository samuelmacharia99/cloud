<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\ResellerInvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerInvoicePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_due_reflects_completed_partial_payments(): void
    {
        $reseller = User::factory()->reseller()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 1000,
            'wallet_amount_applied' => 0,
        ]);

        Payment::create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'amount' => 400,
            'currency' => 'KES',
            'payment_method' => 'manual',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $service = app(ResellerInvoicePaymentService::class);

        $this->assertSame(600.0, $service->amountDue($invoice));
    }
}
