<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Payment;
use App\Models\ResellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class ResellerWalletService
{
    public function __construct(
        protected DatabaseManager $db,
    ) {}

    public function getOrCreate(User $reseller): ResellerWallet
    {
        return ResellerWallet::firstOrCreate(
            ['reseller_id' => $reseller->id],
            [
                'balance' => 0,
                'currency' => 'KES',
                'status' => 'active',
                'low_balance_threshold' => 5000,
                'auto_push_enabled' => true,
            ]
        );
    }

    public function credit(User $reseller, float $amount, string $description, int $paymentId): WalletTransaction
    {
        return $this->db->transaction(function () use ($reseller, $amount, $description, $paymentId) {
            $wallet = $this->getOrCreate($reseller);

            $wallet = ResellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            $wallet->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_id' => $paymentId,
                'reference_type' => 'Payment',
                'status' => 'completed',
            ]);
        });
    }

    public function debit(
        User $reseller,
        float $amount,
        string $description,
        ?int $refId = null,
        ?string $refType = null,
        string $type = 'domain_debit',
    ): WalletTransaction {
        return $this->db->transaction(function () use ($reseller, $amount, $description, $refId, $refType, $type) {
            if ($refId !== null && $refType !== null) {
                $existing = WalletTransaction::query()
                    ->where('reference_id', $refId)
                    ->where('reference_type', $refType)
                    ->where('type', $type)
                    ->where('status', 'completed')
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $wallet = $this->getOrCreate($reseller);

            $wallet = ResellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if (! $wallet->hasSufficientFunds($amount)) {
                throw new InsufficientFundsException($amount, $wallet->balance);
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $amount;

            $wallet->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_id' => $refId,
                'reference_type' => $refType,
                'status' => 'completed',
            ]);
        });
    }

    public function refund(User $reseller, float $amount, string $description, int $domainOrderId): WalletTransaction
    {
        return $this->db->transaction(function () use ($reseller, $amount, $description, $domainOrderId) {
            $wallet = $this->getOrCreate($reseller);

            $wallet = ResellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            $wallet->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'refund',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_id' => $domainOrderId,
                'reference_type' => 'ResellerDomainOrder',
                'status' => 'completed',
            ]);
        });
    }

    public function adjust(User $reseller, float $amount, string $description, User $admin): WalletTransaction
    {
        return $this->db->transaction(function () use ($reseller, $amount, $description, $admin) {
            $wallet = $this->getOrCreate($reseller);

            $wallet = ResellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = (float) $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            if ($balanceAfter < 0) {
                throw new InsufficientFundsException(abs($amount), $balanceBefore);
            }

            $wallet->update(['balance' => $balanceAfter]);

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'adjustment',
                'amount' => abs($amount),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'status' => 'completed',
                'performed_by' => $admin->id,
            ]);

            app(WalletNotificationService::class)->sendManualAdjustmentNotification($transaction, $amount);

            return $transaction;
        });
    }

    public function getTransactions(User $reseller, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $wallet = $this->getOrCreate($reseller);

        $query = $wallet->transactions()->latest();

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->paginate($perPage);
    }

    public function processQueuedOrdersAfterTopup(User $reseller): void
    {
        app(DomainPushService::class)->processQueuedOrders($reseller);
    }

    public function processTopupPayment(Payment $payment): void
    {
        $transaction = $this->db->transaction(function () use ($payment) {
            $existing = WalletTransaction::query()
                ->where('reference_id', $payment->id)
                ->where('reference_type', 'Payment')
                ->where('type', 'deposit')
                ->first();

            if ($existing) {
                return $existing;
            }

            $reseller = $payment->user;

            $transaction = $this->credit(
                $reseller,
                $payment->amount,
                'M-Pesa wallet top-up',
                $payment->id
            );

            if ($payment->invoice) {
                $payment->invoice->update(['status' => 'paid']);
            }

            return $transaction;
        });

        $reseller = $payment->user;

        // Non-critical follow-up actions should not rollback wallet crediting.
        try {
            app(WalletNotificationService::class)->sendTopupConfirmation($transaction);
        } catch (\Throwable $e) {
            Log::warning('Wallet top-up notification failed after successful credit', [
                'payment_id' => $payment->id,
                'wallet_id' => $transaction->wallet_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->processQueuedOrdersAfterTopup($reseller);
        } catch (\Throwable $e) {
            Log::error('Queued order processing failed after wallet top-up credit', [
                'payment_id' => $payment->id,
                'reseller_id' => $reseller->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
