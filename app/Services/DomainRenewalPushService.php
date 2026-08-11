<?php

namespace App\Services;

use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DomainRenewalPushService
{
    public function __construct(
        protected ResellerWalletService $walletService,
        protected DomainRenewalService $renewalService,
        protected WalletNotificationService $walletNotifications,
    ) {}

    public function handlePaidInvoice(Invoice $invoice): void
    {
        if (! $invoice->isPaid()) {
            return;
        }

        DomainRenewalOrder::query()
            ->where(function ($query) use ($invoice) {
                $query->where('customer_invoice_id', $invoice->id)
                    ->orWhere(function ($legacyManaged) use ($invoice) {
                        // Older reseller-managed renewals linked the retail invoice via invoice_id.
                        $legacyManaged->where('invoice_id', $invoice->id)
                            ->whereNotNull('customer_id');
                    })
                    ->orWhere(function ($direct) use ($invoice) {
                        // Platform / direct customer (and reseller self-renewal) invoices:
                        // createInvoice historically set invoice_id only.
                        $direct->where('invoice_id', $invoice->id)
                            ->whereNull('customer_id')
                            ->whereNull('reseller_invoice_id');
                    });
            })
            ->whereNotIn('status', ['pushed', 'completed', 'expired'])
            ->get()
            ->each(fn (DomainRenewalOrder $order) => $this->handleCustomerInvoicePaid($order));

        DomainRenewalOrder::query()
            ->where(function ($query) use ($invoice) {
                $query->where('reseller_invoice_id', $invoice->id)
                    ->orWhere(function ($legacy) use ($invoice) {
                        $legacy->where('invoice_id', $invoice->id)
                            ->whereNull('customer_id')
                            ->whereNull('customer_invoice_id')
                            ->whereNotNull('reseller_id');
                    });
            })
            ->whereNotIn('status', ['pushed', 'completed', 'expired'])
            ->get()
            ->each(fn (DomainRenewalOrder $order) => $this->handleResellerWholesaleInvoicePaid($order));
    }

    public function handleCustomerInvoicePaid(DomainRenewalOrder $order): void
    {
        $order->refresh();

        if (! $order->hasPaidCustomerInvoice()) {
            return;
        }

        if ($order->isResellerManaged()) {
            if ($order->wholesaleAlreadySettled()) {
                $this->renewalService->pushRenewalToAdmin($order->fresh(['domain', 'customer', 'reseller']));

                return;
            }

            if ($this->tryPushUsingWallet($order->fresh(['domain', 'reseller']))) {
                return;
            }

            if ($order->status !== 'queued') {
                $order->update([
                    'status' => 'queued',
                    'paid_at' => $order->paid_at ?? now(),
                ]);
            }

            try {
                $this->walletNotifications->sendDomainRenewalQueuedNotification($order->fresh(['domain', 'reseller', 'customer']));
            } catch (\Throwable $e) {
                Log::error('Failed to notify reseller about queued domain renewal', [
                    'renewal_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $this->renewalService->pushRenewalToAdmin($order->fresh(['domain', 'user']));
    }

    public function handleResellerWholesaleInvoicePaid(DomainRenewalOrder $order): void
    {
        $order->refresh();

        if ($order->isSelfRenewal()) {
            $this->renewalService->pushRenewalToAdmin($order->fresh(['domain', 'user']));

            return;
        }

        if (! $order->isResellerManaged()) {
            return;
        }

        if (! $order->hasPaidWholesaleInvoice()) {
            return;
        }

        if (! $order->hasPaidCustomerInvoice()) {
            return;
        }

        if ($order->wholesaleAlreadySettled() && ! $order->wallet_transaction_id) {
            $this->renewalService->pushRenewalToAdmin($order->fresh(['domain', 'customer', 'reseller']));
        }
    }

    public function tryPushUsingWallet(DomainRenewalOrder $order): bool
    {
        if (! $order->isResellerManaged() || $order->wholesaleAlreadySettled()) {
            return false;
        }

        $reseller = $order->reseller;
        if (! $reseller) {
            return false;
        }

        $amount = $order->effectiveWholesaleAmount();
        if ($amount <= 0) {
            return false;
        }

        $wallet = $this->walletService->getOrCreate($reseller);
        if (! $wallet->hasSufficientFunds($amount)) {
            return false;
        }

        $pushed = false;

        DB::transaction(function () use ($order, $reseller, $amount, &$pushed) {
            $locked = DomainRenewalOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->wholesaleAlreadySettled() || in_array($locked->status, ['pushed', 'completed'], true)) {
                return;
            }

            if (! $locked->hasPaidCustomerInvoice()) {
                return;
            }

            $domain = $locked->domain;
            $label = $domain ? "{$domain->name}{$domain->extension}" : "renewal #{$locked->id}";

            $transaction = $this->walletService->debit(
                $reseller,
                $amount,
                "Domain renewal: {$label}",
                $locked->id,
                'DomainRenewalOrder',
            );

            $locked->update([
                'wallet_transaction_id' => $transaction->id,
                'paid_at' => $locked->paid_at ?? now(),
            ]);

            $pushed = true;
        });

        if ($pushed) {
            $this->renewalService->pushRenewalToAdmin($order->fresh(['domain', 'customer', 'reseller']));
        }

        return $pushed;
    }

    public function processQueuedRenewals(User $reseller): int
    {
        $pushed = 0;

        DomainRenewalOrder::query()
            ->where('reseller_id', $reseller->id)
            ->where('status', 'queued')
            ->where(fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '>', now()))
            ->get()
            ->each(function (DomainRenewalOrder $order) use (&$pushed) {
                if ($this->tryPushUsingWallet($order)) {
                    $pushed++;
                }
            });

        return $pushed;
    }

    /**
     * Re-run paid-invoice handling for renewals stuck before admin push
     * (e.g. historical rows with invoice_id only and status still "invoiced").
     */
    public function pushStuckPaidRenewals(): int
    {
        $orders = DomainRenewalOrder::query()
            ->whereIn('status', ['pending', 'invoiced', 'paid', 'queued'])
            ->where(function ($query) {
                $query->whereNotNull('invoice_id')
                    ->orWhereNotNull('customer_invoice_id')
                    ->orWhereNotNull('reseller_invoice_id');
            })
            ->orderBy('id')
            ->get();

        $advanced = 0;

        foreach ($orders as $order) {
            $before = $order->status;
            $invoiceIds = array_values(array_unique(array_filter([
                $order->customer_invoice_id,
                $order->invoice_id,
                $order->reseller_invoice_id,
            ])));

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::query()->find($invoiceId);
                if ($invoice && $invoice->isPaid()) {
                    $this->handlePaidInvoice($invoice);
                }
            }

            $order->refresh();
            if ($order->status !== $before && in_array($order->status, ['pushed', 'queued', 'completed'], true)) {
                $advanced++;
            }
        }

        return $advanced;
    }
}
