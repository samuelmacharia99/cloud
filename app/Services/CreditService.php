<?php

namespace App\Services;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

class CreditService
{
    /**
     * Create a credit from overpayment
     */
    public static function createFromOverpayment(Payment $payment): ?Credit
    {
        $payment->load('invoice');

        if (!$payment->invoice) {
            return null;
        }

        $overpaidAmount = $payment->amount - $payment->invoice->total;

        if ($overpaidAmount <= 0) {
            return null;
        }

        return Credit::create([
            'user_id' => $payment->user_id,
            'amount' => $overpaidAmount,
            'source' => 'overpayment',
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'notes' => "Overpayment from payment #{$payment->id} on invoice {$payment->invoice->invoice_number}",
            'status' => 'active',
            'expires_at' => now()->addYear(), // Credits expire after 1 year
        ]);
    }

    /**
     * Create a manual credit (by admin)
     */
    public static function createManualCredit(
        User $user,
        float $amount,
        string $reason = 'Manual credit',
        $expiresAt = null
    ): Credit {
        return Credit::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'source' => 'admin',
            'notes' => $reason,
            'status' => 'active',
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Create a refund credit
     */
    public static function createRefundCredit(
        User $user,
        float $amount,
        string $reason = 'Refund'
    ): Credit {
        return Credit::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'source' => 'refund',
            'notes' => $reason,
            'status' => 'active',
            'expires_at' => null, // Refunds don't expire
        ]);
    }

    /**
     * Get total available credit for user
     */
    public static function getAvailableBalance(User $user): float
    {
        return Credit::forUser($user)
            ->active()
            ->get()
            ->sum(function ($credit) {
                return $credit->getAvailableBalance();
            });
    }

    /**
     * Get all active credits for user
     */
    public static function getActiveCredits(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return Credit::forUser($user)
            ->active()
            ->get();
    }

    /**
     * Apply credits to invoice automatically (up to invoice total)
     */
    public static function autoApplyCredits(Invoice $invoice): float
    {
        $invoice->load('user');
        $appliedAmount = 0;
        $remainingBalance = $invoice->getAmountRemaining();

        if ($remainingBalance <= 0) {
            return 0;
        }

        // Get all available credits for this user
        $credits = Credit::forUser($invoice->user)
            ->active()
            ->orderBy('expires_at', 'asc') // Apply expiring credits first
            ->get();

        foreach ($credits as $credit) {
            if ($remainingBalance <= 0) {
                break;
            }

            $availableBalance = $credit->getAvailableBalance();
            if ($availableBalance <= 0) {
                continue;
            }

            // Apply the lesser of available credit or remaining balance
            $applyAmount = min($availableBalance, $remainingBalance);

            if ($credit->applyToInvoice($invoice, $applyAmount)) {
                $appliedAmount += $applyAmount;
                $remainingBalance -= $applyAmount;
            }
        }

        return $appliedAmount;
    }

    /**
     * Apply specific credit to invoice
     */
    public static function applyCredit(Credit $credit, Invoice $invoice, float $amount): bool
    {
        // Validate amount
        if ($amount > $credit->getAvailableBalance()) {
            return false;
        }

        if ($amount > $invoice->getAmountRemaining()) {
            return false;
        }

        return $credit->applyToInvoice($invoice, $amount);
    }

    /**
     * Remove credit from invoice
     */
    public static function removeCredit(Credit $credit, Invoice $invoice): bool
    {
        return $credit->removeFromInvoice($invoice);
    }

    /**
     * Process refund for a payment (create credit)
     */
    public static function refundPayment(Payment $payment, ?float $amount = null): Credit
    {
        $refundAmount = $amount ?? $payment->amount;

        return self::createRefundCredit(
            $payment->user,
            $refundAmount,
            "Refund for payment #{$payment->id} on invoice {$payment->invoice->invoice_number}"
        );
    }

    /**
     * Expire old credits
     */
    public static function expireOldCredits(): int
    {
        return Credit::where('status', 'active')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    /**
     * Get credit summary for invoice
     */
    public static function getInvoiceCreditSummary(Invoice $invoice): array
    {
        $appliedCredits = \DB::table('credit_applications')
            ->where('invoice_id', $invoice->id)
            ->with('credit')
            ->get();

        $totalApplied = $appliedCredits->sum('amount_applied');

        return [
            'total_applied' => $totalApplied,
            'applications' => $appliedCredits->toArray(),
            'balance_after_credits' => max(0, $invoice->total - $totalApplied),
        ];
    }
}
