<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\User;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Database\DatabaseManager;

class DomainPushService
{
    public function __construct(
        protected DatabaseManager $db,
        protected ResellerWalletService $walletService,
        protected WalletNotificationService $notificationService,
    ) {}

    public function handlePaidDomainInvoice(Invoice $invoice): void
    {
        if (! $invoice->isPaid()) {
            return;
        }

        $invoice->loadMissing('items', 'user');

        foreach ($invoice->items as $item) {
            if ($item->product_type !== 'Domain' || ! isset($item->custom_options['domain_order_id'])) {
                continue;
            }

            $order = ResellerDomainOrder::find($item->custom_options['domain_order_id']);

            if (! $order || ! in_array($order->status, ['queued'], true)) {
                continue;
            }

            $order->update([
                'customer_invoice_id' => $order->customer_invoice_id ?? $invoice->id,
            ]);

            if ($order->isPlatformOrder()) {
                $this->pushAfterPlatformCustomerPaid($order);
                app(NotificationService::class)->notifyAdminResellerDomainOrder($order->fresh(), 'pushed');

                continue;
            }

            if ($invoice->user->reseller_id === null) {
                continue;
            }

            if ($this->pushOrQueue($order)) {
                continue;
            }

            $this->notificationService->sendNewCustomerDomainOrderNotification($order);
            app(NotificationService::class)->notifyAdminResellerDomainOrder($order, 'customer_paid');
        }

        $this->processStandaloneTransferItems($invoice);
    }

    public function processStandaloneTransferItems(Invoice $invoice): void
    {
        if (! $invoice->isPaid()) {
            return;
        }

        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if (($item->custom_options['type'] ?? null) !== 'domain_transfer') {
                continue;
            }

            if (! empty($item->custom_options['domain_order_id'])) {
                continue;
            }

            $domain = $item->domain_id ? Domain::find($item->domain_id) : null;

            if (! $domain || ! $domain->isTransfer() || $domain->transfer_status !== 'pending') {
                continue;
            }

            app(RegistrarFulfillmentService::class)
                ->fulfillStandaloneTransfer($domain);
        }
    }

    public function handlePaidResellerInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if ($item->product_type !== 'Domain' || ! isset($item->custom_options['domain_order_id'])) {
                continue;
            }

            $order = ResellerDomainOrder::find($item->custom_options['domain_order_id']);

            if (! $order || $order->status !== 'queued') {
                continue;
            }

            $this->pushAfterWholesalePaidViaInvoice($order);
        }
    }

    /**
     * Idempotent: push any queued domain orders on a paid reseller wholesale invoice.
     */
    public function ensurePaidInvoiceDomainOrdersPushed(Invoice $invoice): void
    {
        if (! $invoice->isPaid()) {
            return;
        }

        $invoice->loadMissing('items', 'user');

        if (! $invoice->user?->is_reseller) {
            return;
        }

        $this->handlePaidResellerInvoice($invoice);
    }

    public function pushOrQueue(ResellerDomainOrder $order): bool
    {
        if ($order->isPlatformOrder()) {
            return false;
        }

        if ($order->requiresCustomerPaymentBeforePush() && ! $order->hasPaidCustomerInvoice()) {
            return false;
        }

        $reseller = $order->reseller;
        $wallet = $this->walletService->getOrCreate($reseller);

        if ($wallet->hasSufficientFunds($order->wholesale_amount)) {
            $this->pushOrderUsingWallet($order);

            return true;
        }

        $order->update([
            'queued_at' => $order->queued_at ?? now(),
            'expires_at' => $order->expires_at ?? now()->addDays(10),
        ]);

        if ($order->hasPaidCustomerInvoice()) {
            $this->notificationService->sendDomainQueuedNotification($order);
        } elseif (! $order->customer_invoice_id) {
            $this->notificationService->sendDomainQueuedNotification($order);
        }

        return false;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function resellerPushOrder(ResellerDomainOrder $order): array
    {
        $order->refresh();

        if ($order->status !== 'queued') {
            return [
                'success' => false,
                'message' => 'Only queued orders can be pushed.',
            ];
        }

        if (! $order->canResellerPush()) {
            return [
                'success' => false,
                'message' => 'Customer payment must be confirmed before this order can be pushed.',
            ];
        }

        if ($order->hasPaidWholesaleInvoice()) {
            $this->pushAfterWholesalePaidViaInvoice($order);

            return [
                'success' => true,
                'message' => 'Order pushed using confirmed wholesale invoice payment.',
            ];
        }

        if ($this->pushOrQueue($order)) {
            return [
                'success' => true,
                'message' => 'Order pushed to admin using wallet funds.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Insufficient wallet balance. Top up your wallet and try again.',
        ];
    }

    public function pushOrderUsingWallet(ResellerDomainOrder $order): void
    {
        $this->db->transaction(function () use ($order) {
            $reseller = $order->reseller;

            $actionLabel = $order->isTransfer() ? 'transfer' : 'registration';
            $transaction = $this->walletService->debit(
                $reseller,
                $order->wholesale_amount,
                "Domain {$actionLabel}: {$order->domain_name}{$order->extension}",
                $order->id,
                'ResellerDomainOrder'
            );

            $adminOrder = $this->createAdminOrderForDomain($order, $reseller, paidViaWallet: true);

            $order->update([
                'status' => 'pushed',
                'pushed_at' => now(),
                'wallet_transaction_id' => $transaction->id,
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminOrder->invoice_id,
            ]);

            $this->markCustomerOrderSubmitted($order);
            $this->notificationService->sendDomainPushedNotification($order);
        });
    }

    public function pushAfterWholesalePaidViaInvoice(ResellerDomainOrder $order): void
    {
        $this->db->transaction(function () use ($order) {
            $adminOrder = $this->createAdminOrderForDomain($order, $order->reseller, paidViaWallet: false);

            $order->update([
                'status' => 'pushed',
                'pushed_at' => now(),
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminOrder->invoice_id,
            ]);

            $this->markCustomerOrderSubmitted($order);
            $this->notificationService->sendDomainPushedNotification($order);
        });
    }

    public function pushAfterPlatformCustomerPaid(ResellerDomainOrder $order): void
    {
        if (! $order->isPlatformOrder()) {
            throw new \InvalidArgumentException('Only platform domain orders can use platform customer payment push.');
        }

        $this->db->transaction(function () use ($order) {
            $adminOrder = $this->createAdminOrderForDomain($order, $order->customer, paidViaWallet: false);

            $order->update([
                'status' => 'pushed',
                'pushed_at' => now(),
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminOrder->invoice_id,
            ]);

            $this->markCustomerOrderSubmitted($order);
        });
    }

    /** @deprecated Use pushAfterWholesalePaidViaInvoice() */
    public function pushOrderWithDirectPayment(ResellerDomainOrder $order): void
    {
        $this->pushAfterWholesalePaidViaInvoice($order);
    }

    /** @deprecated Use pushOrderUsingWallet() */
    public function pushOrder(ResellerDomainOrder $order): void
    {
        $this->pushOrderUsingWallet($order);
    }

    protected function createAdminOrderForDomain(
        ResellerDomainOrder $domainOrder,
        User $owner,
        bool $paidViaWallet,
    ): Order {
        $total = $domainOrder->wholesale_amount + $domainOrder->retail_amount;
        $actionLabel = $domainOrder->isTransfer() ? 'transfer' : 'registration';
        $notes = match (true) {
            $domainOrder->isPlatformOrder() => "Platform domain {$actionLabel} {$domainOrder->fullDomainName()} for {$domainOrder->customer->name}",
            $domainOrder->isSelfOrder() => "Domain {$actionLabel} {$domainOrder->fullDomainName()} (reseller self-order)",
            default => "Domain {$actionLabel} {$domainOrder->fullDomainName()} for customer {$domainOrder->customer->name}",
        };

        $adminOrder = Order::create([
            'user_id' => $owner->id,
            'order_number' => 'ORD-PUSH-'.strtoupper(uniqid()),
            'status' => 'paid',
            'payment_status' => 'paid',
            'total' => $total,
            'notes' => $notes,
        ]);

        $invoiceNumber = 'PUSH-'.strtoupper(uniqid());
        $fulfillmentLabel = $domainOrder->isTransfer() ? 'transfer' : 'register';
        $invoiceNotes = match (true) {
            $domainOrder->isPlatformOrder() => "Platform customer paid {$total} KES — {$fulfillmentLabel} {$domainOrder->fullDomainName()} at registrar",
            $paidViaWallet => "Domain {$actionLabel} pushed to admin via wallet debit (wholesale: {$domainOrder->wholesale_amount} KES)",
            default => "Domain {$actionLabel} pushed to admin after invoice payment (wholesale: {$domainOrder->wholesale_amount} KES)",
        };

        $pushInvoice = Invoice::create([
            'user_id' => $owner->id,
            'order_id' => $adminOrder->id,
            'invoice_number' => $invoiceNumber,
            'status' => 'paid',
            'paid_date' => now(),
            'due_date' => now(),
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'notes' => $invoiceNotes,
        ]);

        InvoiceItem::create([
            'invoice_id' => $pushInvoice->id,
            'product_id' => null,
            'product_type' => 'Domain',
            'description' => $domainOrder->isTransfer()
                ? "Wholesale transfer: {$domainOrder->domain_name}{$domainOrder->extension}"
                : "Wholesale: {$domainOrder->domain_name}{$domainOrder->extension} ({$domainOrder->years}yr)",
            'quantity' => 1,
            'unit_price' => $domainOrder->wholesale_amount,
            'amount' => $domainOrder->wholesale_amount,
            'custom_options' => [
                'type' => 'wholesale_domain',
                'order_type' => $domainOrder->order_type?->value ?? 'registration',
                'domain_order_id' => $domainOrder->id,
                'customer_id' => $domainOrder->customer_id,
                'customer_invoice_id' => $domainOrder->customer_invoice_id,
            ],
        ]);

        if ($domainOrder->retail_amount > 0) {
            InvoiceItem::create([
                'invoice_id' => $pushInvoice->id,
                'product_id' => null,
                'product_type' => 'Domain',
                'description' => "Retail margin: {$domainOrder->domain_name}{$domainOrder->extension}",
                'quantity' => 1,
                'unit_price' => $domainOrder->retail_amount,
                'amount' => $domainOrder->retail_amount,
                'custom_options' => ['type' => 'retail_markup'],
            ]);
        }

        $adminOrder->update(['invoice_id' => $pushInvoice->id]);

        return $adminOrder;
    }

    protected function markCustomerOrderSubmitted(ResellerDomainOrder $order): void
    {
        if ($order->isTransfer()) {
            $domain = Domain::find($order->domain_id);
            if ($domain && $domain->isTransfer() && $domain->transfer_status === 'pending') {
                app(RegistrarFulfillmentService::class)
                    ->fulfillStandaloneTransfer($domain);
            }

            return;
        }

        $services = Service::query()
            ->where('user_id', $order->customer_id)
            ->where(function ($query) use ($order) {
                $query->whereJsonContains('service_meta->domain_id', $order->domain_id)
                    ->orWhere('name', $order->domain_name.$order->extension);
            })
            ->get();

        foreach ($services as $service) {
            $service->update(['status' => ServiceStatus::Provisioning->value]);
        }
    }

    /**
     * Admin push: prefer paid wholesale invoice (M-Pesa/card/etc.), then wallet.
     *
     * @return array{success: bool, message: string}
     */
    public function adminPushOrder(ResellerDomainOrder $order): array
    {
        $order->refresh();

        if ($order->status !== 'queued') {
            return [
                'success' => false,
                'message' => 'Only queued orders can be pushed.',
            ];
        }

        if ($order->isPlatformOrder()) {
            if (! $order->hasPaidCustomerInvoice()) {
                return [
                    'success' => false,
                    'message' => 'Customer has not paid for this domain order yet.',
                ];
            }

            $this->pushAfterPlatformCustomerPaid($order);

            return [
                'success' => true,
                'message' => 'Platform domain order pushed for registrar fulfillment.',
            ];
        }

        if ($order->hasPaidWholesaleInvoice()) {
            $this->pushAfterWholesalePaidViaInvoice($order);

            return [
                'success' => true,
                'message' => 'Order pushed using confirmed wholesale invoice payment (no wallet debit).',
            ];
        }

        if ($this->pushOrQueue($order)) {
            return [
                'success' => true,
                'message' => 'Order pushed using reseller wallet funds.',
            ];
        }

        return [
            'success' => false,
            'message' => 'No paid wholesale invoice found and reseller wallet balance is insufficient.',
        ];
    }

    public function prepareOrderForAdminCompletion(ResellerDomainOrder $order): void
    {
        $order->refresh();

        if (in_array($order->status, ['pushed', 'failed'], true)) {
            return;
        }

        if ($order->status === 'queued' && $order->hasPaidWholesaleInvoice()) {
            $this->pushAfterWholesalePaidViaInvoice($order);

            return;
        }

        if ($order->status === 'queued' && $order->isPlatformOrder() && $order->hasPaidCustomerInvoice()) {
            $this->pushAfterPlatformCustomerPaid($order);

            return;
        }

        throw new \InvalidArgumentException(
            'Order must be pushed or backed by a paid invoice before it can be completed.',
        );
    }

    public function processQueuedOrders(User $reseller): int
    {
        $queued = ResellerDomainOrder::where('reseller_id', $reseller->id)
            ->where('status', 'queued')
            ->where('expires_at', '>', now())
            ->get();

        $pushed = 0;

        foreach ($queued as $order) {
            try {
                if (! $order->canResellerPush()) {
                    continue;
                }

                if ($this->pushOrQueue($order)) {
                    $pushed++;
                }
            } catch (\Exception $e) {
                \Log::error("Failed to push domain order {$order->id}: {$e->getMessage()}");
            }
        }

        return $pushed;
    }

    public function completeOrder(ResellerDomainOrder $order, string $registrar): void
    {
        $this->db->transaction(function () use ($order, $registrar) {
            $domain = Domain::find($order->domain_id);

            if ($domain && $order->isTransfer()) {
                DomainTransferService::completeTransfer($domain, $registrar);
            } elseif ($domain) {
                $registrationDate = now();
                $expiryDate = $domain->expires_at
                    ?? $registrationDate->copy()->addYears($order->years);

                $domain->update([
                    'status' => 'active',
                    'registrar' => $registrar,
                    'registered_at' => $registrationDate,
                    'expires_at' => $expiryDate,
                ]);
            }

            if ($order->domain_id && $order->isRegistration()) {
                Service::query()
                    ->where('user_id', $order->customer_id)
                    ->where(function ($query) use ($order) {
                        $query->whereJsonContains('service_meta->domain_id', $order->domain_id)
                            ->orWhere('name', $order->domain_name.$order->extension);
                    })
                    ->update(['status' => ServiceStatus::Active->value]);
            }

            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

            $this->notificationService->sendDomainCompletedNotification($order);
        });
    }

    public function failOrder(ResellerDomainOrder $order, string $reason): void
    {
        $this->db->transaction(function () use ($order, $reason) {
            $order->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $reason,
                'retry_count' => $order->retry_count + 1,
            ]);

            if ($order->wallet_transaction_id && $order->reseller) {
                $transaction = $order->walletTransaction;
                $this->walletService->refund(
                    $order->reseller,
                    $transaction->amount,
                    "Refund for failed domain order: {$reason}",
                    $order->id
                );
            }

            $this->notificationService->sendDomainFailedNotification($order);
        });
    }

    public function cancelOrder(ResellerDomainOrder $order, ?string $reason = null): void
    {
        if (! $order->canCancel()) {
            throw new \InvalidArgumentException('This order cannot be cancelled.');
        }

        $this->db->transaction(function () use ($order, $reason) {
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'failure_reason' => $reason ?? $order->failure_reason ?? 'Cancelled by reseller',
            ]);

            $this->removePendingDomain($order);
        });
    }

    public function deleteOrder(ResellerDomainOrder $order): void
    {
        if (! $order->canDelete()) {
            throw new \InvalidArgumentException('This order cannot be deleted.');
        }

        $this->db->transaction(function () use ($order) {
            $this->removePendingDomain($order);
            $order->delete();
        });
    }

    protected function removePendingDomain(ResellerDomainOrder $order): void
    {
        $domain = $order->domain;

        if ($domain && $domain->status === 'pending') {
            if ($domain->isTransfer() && ! in_array($domain->transfer_status, ['pending', 'initiated'], true)) {
                return;
            }

            $domain->delete();
        }
    }
}
