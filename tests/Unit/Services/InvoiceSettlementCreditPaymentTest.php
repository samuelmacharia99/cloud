<?php

namespace Tests\Unit\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSettlementCreditPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_invoice_as_paid_updates_linked_order(): void
    {
        $user = User::factory()->customer()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'paid_date' => null,
            'total' => 500,
            'subtotal' => 500,
            'tax' => 0,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        app(InvoiceSettlementService::class)->markInvoiceAsPaid($invoice);

        $invoice->refresh();
        $order->refresh();

        $this->assertTrue($invoice->isPaid());
        $this->assertNotNull($invoice->paid_date);
        $this->assertSame('paid', $order->status);
        $this->assertSame('paid', $order->payment_status);
    }
}
