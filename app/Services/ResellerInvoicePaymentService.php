<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($invoice, $reseller, $shouldApply) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            $this->syncWalletAmountApplied($invoice);

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

            $debitType = $invoice->type === 'reseller_subscription' ? 'subscription_debit' : 'domain_debit';

            $this->walletService->debit(
                $reseller,
                $toApply,
                $invoice->type === 'reseller_subscription'
                    ? "Package subscription invoice {$invoice->invoice_number}"
                    : "Applied to invoice {$invoice->invoice_number}",
                $invoice->id,
                'Invoice',
                $debitType,
                idempotent: false,
            );

            $this->syncWalletAmountApplied($invoice->fresh());

            return [
                'wallet_applied' => (float) $invoice->fresh()->wallet_amount_applied,
                'amount_due' => $this->amountDue($invoice->fresh()),
            ];
        });
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

    private function syncWalletAmountApplied(Invoice $invoice): void
    {
        $applied = (float) WalletTransaction::query()
            ->where('reference_id', $invoice->id)
            ->where('reference_type', 'Invoice')
            ->whereIn('type', ['subscription_debit', 'domain_debit'])
            ->where('status', 'completed')
            ->sum('amount');

        $applied = round($applied, 2);

        if (abs($applied - (float) $invoice->wallet_amount_applied) > 0.001) {
            $invoice->update(['wallet_amount_applied' => $applied]);
            $invoice->refresh();
        }
    }
}
