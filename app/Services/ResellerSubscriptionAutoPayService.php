<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When a reseller package renewal invoice is created, attempt to pay it in full from wallet balance.
 */
class ResellerSubscriptionAutoPayService
{
    public function __construct(
        private ResellerInvoicePaymentService $invoicePayments,
        private ResellerWalletService $wallets,
    ) {}

    public function isEnabled(): bool
    {
        return in_array(
            Setting::getValue('reseller_auto_pay_subscription_from_wallet', 'true'),
            ['1', 'true', true],
            true
        );
    }

    public function isEligible(Invoice $invoice): bool
    {
        if ($invoice->type !== 'reseller_subscription') {
            return false;
        }

        $status = $invoice->status->value ?? $invoice->status;

        return in_array($status, ['unpaid', 'overdue'], true);
    }

    /**
     * Pay the invoice in full from wallet when balance covers the total due.
     * Partial wallet auto-pay is intentionally not supported.
     */
    public function attempt(Invoice $invoice): bool
    {
        if (! $this->isEnabled() || ! $this->isEligible($invoice)) {
            return false;
        }

        $reseller = $invoice->user;
        if (! $reseller instanceof User || ! $reseller->is_reseller) {
            return false;
        }

        $amountDue = $this->invoicePayments->amountDue($invoice);
        if ($amountDue <= 0) {
            return false;
        }

        $wallet = $this->wallets->getOrCreate($reseller);
        if ((float) $wallet->balance < $amountDue) {
            Log::info('Reseller subscription auto-pay waiting for wallet top-up', [
                'invoice_id' => $invoice->id,
                'reseller_id' => $reseller->id,
                'wallet_balance' => $wallet->balance,
                'amount_due' => $amountDue,
            ]);

            return false;
        }

        try {
            $paidInvoiceId = DB::transaction(function () use ($invoice, $reseller) {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

                if (! $locked || ! $this->isEligible($locked)) {
                    return null;
                }

                $amountDue = $this->invoicePayments->amountDue($locked);
                if ($amountDue <= 0) {
                    return null;
                }

                $wallet = $this->wallets->getOrCreate($reseller);
                if ((float) $wallet->balance < $amountDue) {
                    return null;
                }

                $this->invoicePayments->applyWallet($locked, $reseller, true);

                return $locked->id;
            });

            if (! $paidInvoiceId) {
                return false;
            }

            $paidInvoice = Invoice::query()->find($paidInvoiceId);

            if (! $paidInvoice || $this->invoicePayments->amountDue($paidInvoice) > 0) {
                return false;
            }

            $subscriptionService = app(ResellerPackageSubscriptionService::class);

            $markedPaid = Invoice::withoutEvents(function () use ($paidInvoice) {
                return $this->invoicePayments->completeInvoiceIfFullyPaid($paidInvoice);
            });

            if (! $markedPaid) {
                return false;
            }

            $paidInvoice = $paidInvoice->fresh();
            $subscriptionService->activateFromPaidInvoice($paidInvoice);
            $paidInvoice = $paidInvoice->fresh();

            app(NotificationService::class)->notifyPaymentReceived($paidInvoice);
            app(WalletNotificationService::class)->sendSubscriptionAutoPayNotification($paidInvoice);

            Log::info('Reseller subscription invoice auto-paid from wallet', [
                'invoice_id' => $paidInvoice->id,
                'reseller_id' => $reseller->id,
                'amount' => $paidInvoice->total,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Reseller subscription auto-pay failed', [
                'invoice_id' => $invoice->id,
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
