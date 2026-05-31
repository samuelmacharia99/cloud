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
        if ($invoice->user->reseller_id === null) {
            return;
        }

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

            if ($this->pushOrQueue($order)) {
                continue;
            }

            $this->notificationService->sendNewCustomerDomainOrderNotification($order);
        }
    }

    public function handlePaidResellerInvoice(Invoice $invoice): void
    {
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

    public function pushOrQueue(ResellerDomainOrder $order): bool
    {
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

        if (! $order->customer_invoice_id) {
            $this->notificationService->sendDomainQueuedNotification($order);
        }

        return false;
    }

    public function pushOrderUsingWallet(ResellerDomainOrder $order): void
    {
        $this->db->transaction(function () use ($order) {
            $reseller = $order->reseller;

            $transaction = $this->walletService->debit(
                $reseller,
                $order->wholesale_amount,
                "Domain registration: {$order->domain_name}{$order->extension}",
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
        User $reseller,
        bool $paidViaWallet,
    ): Order {
        $adminOrder = Order::create([
            'user_id' => $reseller->id,
            'order_number' => 'ORD-PUSH-'.strtoupper(uniqid()),
            'status' => 'paid',
            'payment_status' => 'paid',
            'total' => $domainOrder->wholesale_amount + $domainOrder->retail_amount,
            'notes' => "Domain order {$domainOrder->domain_name}{$domainOrder->extension} for customer {$domainOrder->customer->name}",
        ]);

        $invoiceNumber = 'PUSH-'.strtoupper(uniqid());

        $pushInvoice = Invoice::create([
            'user_id' => $reseller->id,
            'order_id' => $adminOrder->id,
            'invoice_number' => $invoiceNumber,
            'status' => 'paid',
            'paid_date' => now(),
            'due_date' => now(),
            'subtotal' => $domainOrder->wholesale_amount + $domainOrder->retail_amount,
            'tax' => 0,
            'total' => $domainOrder->wholesale_amount + $domainOrder->retail_amount,
            'notes' => $paidViaWallet
                ? "Domain pushed to admin via wallet debit (wholesale: {$domainOrder->wholesale_amount} KES)"
                : "Domain pushed to admin after invoice payment (wholesale: {$domainOrder->wholesale_amount} KES)",
        ]);

        InvoiceItem::create([
            'invoice_id' => $pushInvoice->id,
            'product_id' => null,
            'product_type' => 'Domain',
            'description' => "Wholesale: {$domainOrder->domain_name}{$domainOrder->extension} ({$domainOrder->years}yr)",
            'quantity' => 1,
            'unit_price' => $domainOrder->wholesale_amount,
            'amount' => $domainOrder->wholesale_amount,
            'custom_options' => [
                'type' => 'wholesale_domain',
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

    public function processQueuedOrders(User $reseller): int
    {
        $queued = ResellerDomainOrder::where('reseller_id', $reseller->id)
            ->where('status', 'queued')
            ->where('expires_at', '>', now())
            ->get();

        $pushed = 0;

        foreach ($queued as $order) {
            try {
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

            if ($domain) {
                $registrationDate = now();
                $expiryDate = $registrationDate->copy()->addYears($order->years);

                $domain->update([
                    'status' => 'active',
                    'registrar' => $registrar,
                    'registered_at' => $registrationDate,
                    'expires_at' => $expiryDate,
                ]);
            }

            if ($order->domain_id) {
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

            if ($order->wallet_transaction_id) {
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
}
