<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ResellerDomainOrder;
use App\Models\User;
use Carbon\Carbon;
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
        foreach ($invoice->items as $item) {
            if ($item->product_type === 'Domain' && isset($item->custom_options['domain_order_id'])) {
                $domainOrderId = $item->custom_options['domain_order_id'];
                $order = ResellerDomainOrder::find($domainOrderId);

                if ($order && $order->status === 'queued') {
                    $this->pushOrQueue($order);
                }
            }
        }
    }

    public function pushOrQueue(ResellerDomainOrder $order): bool
    {
        $reseller = $order->reseller;
        $wallet = $this->walletService->getOrCreate($reseller);

        if ($wallet->hasSufficientFunds($order->wholesale_amount)) {
            $this->pushOrder($order);
            return true;
        }

        $order->update([
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $this->notificationService->sendDomainQueuedNotification($order);

        return false;
    }

    public function pushOrder(ResellerDomainOrder $order): void
    {
        $this->db->transaction(function () use ($order) {
            $reseller = $order->reseller;

            $transaction = $this->walletService->debit(
                $reseller,
                $order->wholesale_amount,
                "Domain registration: {$order->domain_name}.{$order->extension}",
                $order->id,
                'ResellerDomainOrder'
            );

            $adminOrder = $this->createAdminOrderForDomain($order, $reseller);

            $order->update([
                'status' => 'pushed',
                'pushed_at' => now(),
                'wallet_transaction_id' => $transaction->id,
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminOrder->invoice_id,
            ]);

            $this->notificationService->sendDomainPushedNotification($order);
        });
    }

    protected function createAdminOrderForDomain(ResellerDomainOrder $order, User $reseller): Order
    {
        $order = Order::create([
            'user_id' => $reseller->id,
            'status' => 'pending',
            'total' => $order->wholesale_amount + $order->retail_amount,
            'notes' => "Domain order {$order->domain_name}.{$order->extension} for customer {$order->customer->name}",
        ]);

        $invoice = Invoice::create([
            'user_id' => $reseller->id,
            'order_id' => $order->id,
            'status' => 'draft',
            'total' => $order->total,
            'notes' => "Domain registration pushed to admin - wholesale: {$order->wholesale_amount}, retail markup: {$order->retail_amount}",
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => null,
            'description' => "Wholesale: {$order->domain_name}.{$order->extension} ({$order->years}yr)",
            'quantity' => 1,
            'unit_price' => $order->wholesale_amount,
            'custom_options' => ['type' => 'wholesale_domain'],
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => null,
            'description' => "Retail Markup: {$order->domain_name}.{$order->extension}",
            'quantity' => 1,
            'unit_price' => $order->retail_amount,
            'custom_options' => ['type' => 'retail_markup'],
        ]);

        $order->update(['invoice_id' => $invoice->id]);

        return $order;
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
                $expiryDate = $registrationDate->addYears($order->years);

                $domain->update([
                    'status' => 'active',
                    'registrar' => $registrar,
                    'registered_at' => $registrationDate,
                    'expires_at' => $expiryDate,
                ]);
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

    public function pushOrderWithDirectPayment(ResellerDomainOrder $order): void
    {
        $this->db->transaction(function () use ($order) {
            // No wallet debit — payment received directly via M-Pesa/Stripe/etc.
            $adminOrder = $this->createAdminOrderForDomain($order, $order->reseller);

            $order->update([
                'status' => 'pushed',
                'pushed_at' => now(),
                'admin_order_id' => $adminOrder->id,
                'admin_invoice_id' => $adminOrder->invoice_id ?? null,
            ]);

            $this->notificationService->sendDomainPushedNotification($order);
        });
    }
}
