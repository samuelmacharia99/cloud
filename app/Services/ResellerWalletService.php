<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Payment;
use App\Models\ResellerWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;

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

            $wallet = $wallet->lockForUpdate()->first() ?? $wallet;

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

    public function debit(User $reseller, float $amount, string $description, ?int $refId = null, ?string $refType = null): WalletTransaction
    {
        return $this->db->transaction(function () use ($reseller, $amount, $description, $refId, $refType) {
            $wallet = $this->getOrCreate($reseller);

            $wallet = ResellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if (!$wallet->hasSufficientFunds($amount)) {
                throw new InsufficientFundsException($amount, $wallet->balance);
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $amount;

            $wallet->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'domain_debit',
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

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            $wallet->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'adjustment',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'status' => 'completed',
                'performed_by' => $admin->id,
            ]);
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
        $this->db->transaction(function () use ($payment) {
            $reseller = $payment->user;

            $transaction = $this->credit(
                $reseller,
                $payment->amount,
                "M-Pesa wallet top-up",
                $payment->id
            );

            if ($payment->invoice) {
                $payment->invoice->update(['status' => 'paid']);
            }

            app(WalletNotificationService::class)->sendTopupConfirmation($transaction);

            $this->processQueuedOrdersAfterTopup($reseller);
        });
    }
}
