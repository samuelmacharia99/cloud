<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

class ResellerInvoicePaymentService
{
    public function __construct(
        protected ResellerWalletService $walletService,
    ) {}

    public function amountDue(Invoice $invoice): float
    {
        return max(0, round($invoice->fresh()->getAmountRemaining(), 2));
    }

    /**
     * @return array{wallet_applied: float, amount_due: float}
     */
    public function applyWallet(Invoice $invoice, User $reseller, bool $shouldApply): array
    {
        if (! $shouldApply) {
            return [
                'wallet_applied' => (float) $invoice->wallet_amount_applied,
                'amount_due' => $this->amountDue($invoice),
            ];
        }

        $amountDue = $this->amountDue($invoice);

        if ($amountDue <= 0) {
            return [
                'wallet_applied' => (float) $invoice->wallet_amount_applied,
                'amount_due' => 0,
            ];
        }

        $wallet = $this->walletService->getOrCreate($reseller);
        $toApply = min((float) $wallet->balance, $amountDue);

        if ($toApply <= 0) {
            return [
                'wallet_applied' => (float) $invoice->wallet_amount_applied,
                'amount_due' => $amountDue,
            ];
        }

        $this->walletService->debit(
            $reseller,
            $toApply,
            $invoice->type === 'reseller_subscription'
                ? "Package subscription invoice {$invoice->invoice_number}"
                : "Applied to invoice {$invoice->invoice_number}",
            $invoice->id,
            'Invoice',
            $invoice->type === 'reseller_subscription' ? 'subscription_debit' : 'domain_debit',
        );

        $invoice->update([
            'wallet_amount_applied' => round((float) $invoice->wallet_amount_applied + $toApply, 2),
        ]);

        return [
            'wallet_applied' => (float) $invoice->fresh()->wallet_amount_applied,
            'amount_due' => $this->amountDue($invoice->fresh()),
        ];
    }

    public function completeInvoiceIfFullyPaid(Invoice $invoice, ?Payment $gatewayPayment = null): bool
    {
        $invoice->refresh();

        if ($gatewayPayment && $gatewayPayment->status !== PaymentStatus::Completed) {
            $gatewayPayment->update([
                'status' => PaymentStatus::Completed,
                'paid_at' => now(),
            ]);
        }

        $invoice->refresh();

        if ($invoice->isPaid()) {
            if (! $invoice->paid_date) {
                $invoice->update(['paid_date' => now()]);
            }

            return true;
        }

        if (! $gatewayPayment && $this->amountDue($invoice) <= 0) {
            $invoice->update([
                'status' => 'paid',
                'paid_date' => now(),
            ]);

            return true;
        }

        if ($invoice->getAmountRemaining() > 0) {
            return false;
        }

        $invoice->update([
            'status' => 'paid',
            'paid_date' => now(),
        ]);

        return true;
    }
}
