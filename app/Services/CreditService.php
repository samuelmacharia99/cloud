<?php

namespace App\Services;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceCurrencyService;
use Illuminate\Database\Eloquent\Collection;

class CreditService
{
    /**
     * Create a credit from overpayment
     */
    public static function createFromOverpayment(Payment $payment): ?Credit
    {
        $payment->load('invoice');

        if (! $payment->invoice) {
            return null;
        }

        $overpaidAmount = app(InvoiceCurrencyService::class)->paymentOverpaymentInKes(
            $payment->invoice,
            (float) $payment->amount,
            $payment->currency ?? config('currency.base', 'KES')
        );

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
     * Create a credit from a customer purchase
     */
    public static function createPurchaseCredit(User $user, float $amount, Payment $payment): Credit
    {
        return Credit::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'source' => 'purchase',
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'notes' => 'Credit purchase via '.$payment->payment_method->value,
            'status' => 'active',
            'expires_at' => now()->addYear(),
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
    public static function getActiveCredits(User $user): Collection
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

            $availableBalanceKes = $credit->getAvailableBalance();
            if ($availableBalanceKes <= 0) {
                continue;
            }

            $remainingKes = $invoice->displayCurrency() === config('currency.base', 'KES')
                ? $remainingBalance
                : round($remainingBalance / max((float) $invoice->exchange_rate, 0.00000001), 2);

            $applyAmountKes = min($availableBalanceKes, $remainingKes);

            if ($credit->applyToInvoice($invoice, $applyAmountKes)) {
                $appliedInvoiceAmount = $invoice->displayCurrency() === config('currency.base', 'KES')
                    ? $applyAmountKes
                    : round($applyAmountKes * (float) $invoice->exchange_rate, 2);

                $appliedAmount += $appliedInvoiceAmount;
                $remainingBalance -= $appliedInvoiceAmount;
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
     * Deduct available credit from a user (FIFO — expiring credits first).
     *
     * @return array{deducted: float, credits: list<array{credit_id: int, amount: float}>}
     */
    public static function deductFromUser(User $user, float $amount, string $reason): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        $available = self::getAvailableBalance($user);
        if ($amount > $available) {
            throw new \InvalidArgumentException('Insufficient credit balance.');
        }

        $remaining = $amount;
        $affected = [];

        $credits = Credit::forUser($user)
            ->active()
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('created_at')
            ->get();

        foreach ($credits as $credit) {
            if ($remaining <= 0) {
                break;
            }

            $creditAvailable = $credit->getAvailableBalance();
            if ($creditAvailable <= 0) {
                continue;
            }

            $deduct = min($creditAvailable, $remaining);
            self::deductFromCredit($credit, $deduct, $reason);
            $affected[] = ['credit_id' => $credit->id, 'amount' => $deduct];
            $remaining -= $deduct;
        }

        return ['deducted' => $amount, 'credits' => $affected];
    }

    /**
     * Deduct from a single credit's available balance.
     */
    public static function deductFromCredit(Credit $credit, float $amount, string $reason): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        $available = $credit->getAvailableBalance();
        if ($amount > $available) {
            throw new \InvalidArgumentException('Amount exceeds available balance on this credit.');
        }

        $totalApplied = (float) ($credit->appliedToInvoices()->sum('amount_applied') ?? 0);
        $newAmount = round((float) $credit->amount - $amount, 2);

        $noteLine = sprintf(
            'Admin deduction of KES %s on %s: %s',
            number_format($amount, 2),
            now()->format('Y-m-d H:i'),
            $reason
        );
        $notes = trim(($credit->notes ?? '')."\n".$noteLine);

        $updates = [
            'amount' => $newAmount,
            'notes' => $notes,
        ];

        $remainingAvailable = $newAmount - $totalApplied;
        if ($remainingAvailable <= 0) {
            $updates['status'] = $newAmount <= 0 ? 'refunded' : 'applied';
        }

        $credit->update($updates);
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
