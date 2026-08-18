<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceNumberService;
use Illuminate\Support\Facades\DB;

class CustomerCreditTopupService
{
    public function __construct(
        private InvoiceNumberService $invoiceNumbers,
    ) {}

    public function createTopupInvoice(User $customer, float $amount): Invoice
    {
        $invoice = $this->invoiceNumbers->createWithUniqueNumber(
            fn (string $number) => Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => $number,
                'status' => 'unpaid',
                'due_date' => now()->addDays(7),
                'subtotal' => $amount,
                'tax' => 0,
                'total' => $amount,
                'notes' => "Account credit purchase: {$amount} KES",
            ]),
            prefix: 'CREDIT',
        );

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Account credit top-up',
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        return $invoice;
    }

    public function markPendingPaymentPurpose(Payment $payment): void
    {
        $payment->update(['payment_purpose' => 'credit_topup']);
    }

    public function processTopupPayment(Payment $payment): Credit
    {
        $credit = DB::transaction(function () use ($payment) {
            $existing = Credit::query()
                ->where('payment_id', $payment->id)
                ->where('source', 'purchase')
                ->first();

            if ($existing) {
                return $existing;
            }

            $credit = CreditService::createPurchaseCredit(
                $payment->user,
                (float) $payment->amount,
                $payment
            );

            if ($payment->invoice) {
                $payment->invoice->update(['status' => InvoiceStatus::Paid->value]);
            }

            return $credit;
        });

        app(DomainAutoRenewService::class)->retryAfterPrepaidCredit();

        return $credit;
    }
}
