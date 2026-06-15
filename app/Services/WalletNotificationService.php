<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Mail\ResellerDomainOrderMail;
use App\Models\Invoice;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerWallet;
use App\Models\WalletTransaction;
use App\Services\Telegram\TelegramMonitorBridge;

class WalletNotificationService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
        private EmailDeliveryService $emailDelivery,
        private NotificationPreferenceService $preferences,
        private NotificationService $notificationService,
        private SmsService $smsService,
    ) {}

    public function sendLowBalanceAlert(ResellerWallet $wallet): void
    {
        if (! $wallet->needsLowBalanceAlert()) {
            return;
        }

        $reseller = $wallet->reseller;
        app(TelegramMonitorBridge::class)->walletLowBalance($reseller, $wallet->getFormattedBalance());

        $company = $this->brandingResolver->forReseller($reseller)['company_name'];
        $event = NotificationEvent::ResellerWalletLow;
        $walletUrl = route('reseller.wallet.index');

        $message = "Your {$company} wallet balance is low: {$wallet->getFormattedBalance()}. Top up now: {$walletUrl}";

        try {
            $this->smsService->send($reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send low balance SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($reseller->email && $this->preferences->isEmailEnabledForUser($reseller, $event)) {
            $this->emailDelivery->sendTemplated($reseller, $event, [
                'reseller_name' => $reseller->name,
                'balance' => $wallet->getFormattedBalance(),
                'wallet_url' => $walletUrl,
                'site_name' => $company,
            ]);
        }

        $wallet->update(['last_low_balance_alert_at' => now()]);
    }

    public function sendManualAdjustmentNotification(WalletTransaction $transaction, float $signedAmount): void
    {
        $transaction->loadMissing('wallet.reseller');
        $reseller = $transaction->wallet->reseller;
        app(TelegramMonitorBridge::class)->walletAdjustment(
            $reseller,
            $signedAmount,
            (float) $transaction->balance_after,
            $transaction->description,
        );

        $event = NotificationEvent::ResellerWalletAdjustment;
        $currency = $transaction->wallet->currency ?? 'KES';
        $previous = number_format((float) $transaction->balance_before, 2);
        $newBalance = number_format((float) $transaction->balance_after, 2);
        $amountFormatted = number_format(abs($signedAmount), 2);
        $action = $signedAmount >= 0 ? 'credited' : 'debited';
        $company = $this->brandingResolver->forReseller($reseller)['company_name'];

        $smsMessage = "{$company}: Wallet {$action} {$currency} {$amountFormatted}. Previous: {$currency} {$previous}. New balance: {$currency} {$newBalance}.";

        try {
            $this->smsService->send($reseller->phone, $smsMessage);
        } catch (\Exception $e) {
            \Log::error("Failed to send wallet adjustment SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($reseller->email && $this->preferences->isEmailEnabledForUser($reseller, $event)) {
            $this->emailDelivery->sendTemplated($reseller, $event, [
                'reseller_name' => $reseller->name,
                'amount' => "{$currency} {$amountFormatted}",
                'previous_balance' => "{$currency} {$previous}",
                'new_balance' => "{$currency} {$newBalance}",
                'adjustment_type' => $signedAmount >= 0 ? 'Credit (top-up)' : 'Debit (deduction)',
                'reason' => $transaction->description,
                'site_name' => $company,
            ]);
        }
    }

    public function sendSubscriptionAutoPayNotification(Invoice $invoice): void
    {
        $invoice->loadMissing('user');
        $reseller = $invoice->user;

        if (! $reseller) {
            return;
        }

        $wallet = $reseller->wallet ?? app(ResellerWalletService::class)->getOrCreate($reseller);
        $currency = $wallet->currency ?? 'KES';
        $amount = number_format((float) $invoice->total, 2);
        $balance = number_format((float) $wallet->balance, 2);
        $company = $this->brandingResolver->forReseller($reseller)['company_name'];

        $smsMessage = "{$company}: Package invoice {$invoice->invoice_number} ({$currency} {$amount}) was paid automatically from your wallet. New balance: {$currency} {$balance}.";

        try {
            $this->smsService->send($reseller->phone, $smsMessage);
        } catch (\Exception $e) {
            \Log::error("Failed to send subscription auto-pay SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        $event = NotificationEvent::ResellerWalletTopup;
        if ($reseller->email && $this->preferences->isEmailEnabledForUser($reseller, $event)) {
            $this->emailDelivery->sendTemplated($reseller, $event, [
                'reseller_name' => $reseller->name,
                'amount' => "{$currency} {$amount} (auto-debit)",
                'balance' => "{$currency} {$balance}",
                'site_name' => $company,
            ]);
        }
    }

    public function sendTopupConfirmation(WalletTransaction $transaction): void
    {
        $transaction->loadMissing('wallet.reseller');
        $reseller = $transaction->wallet->reseller;
        app(TelegramMonitorBridge::class)->walletTopup(
            $reseller,
            (float) $transaction->amount,
            (float) $transaction->balance_after,
        );

        $event = NotificationEvent::ResellerWalletTopup;
        $message = "Wallet top-up confirmed! Amount: {$transaction->amount} KES. New balance: {$transaction->balance_after} KES";

        try {
            $this->smsService->send($reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send topup SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($reseller->email && $this->preferences->isEmailEnabledForUser($reseller, $event)) {
            $company = $this->brandingResolver->forReseller($reseller)['company_name'];
            try {
                $this->emailDelivery->sendTemplated($reseller, $event, [
                    'reseller_name' => $reseller->name,
                    'amount' => number_format((float) $transaction->amount, 2).' KES',
                    'balance' => number_format((float) $transaction->balance_after, 2).' KES',
                    'site_name' => $company,
                ]);
            } catch (\Throwable $e) {
                \Log::warning("Failed to send topup email to reseller {$reseller->id}: {$e->getMessage()}");
            }
        }
    }

    public function sendNewCustomerDomainOrderNotification(ResellerDomainOrder $order): void
    {
        $this->notifyResellerDomainOrder($order, NotificationEvent::ResellerNewCustomerOrder);
    }

    public function sendDomainQueuedNotification(ResellerDomainOrder $order): void
    {
        $this->notifyResellerDomainOrder($order, NotificationEvent::ResellerDomainQueued, 'queued');
    }

    public function sendDomainPushedNotification(ResellerDomainOrder $order): void
    {
        $this->notifyResellerDomainOrder($order, NotificationEvent::ResellerDomainPushed, 'pushed');
        $this->notifyAdminDomainPush($order);
    }

    public function sendDomainCompletedNotification(ResellerDomainOrder $order): void
    {
        $reseller = $order->reseller;
        $customer = $order->customer;
        $company = $this->brandingResolver->forReseller($reseller)['company_name'];

        $domain = $order->fullDomainName();
        $resellerMessage = "Domain {$domain} has been registered successfully!";
        $customerMessage = "{$company}: Domain {$domain} has been registered successfully!";

        try {
            $this->smsService->send($reseller->phone, $resellerMessage);
        } catch (\Exception $e) {
            \Log::error("Failed to send completed domain SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($customer->phone) {
            try {
                app('talksasa-sms-service')->sendSms($reseller, $customer->phone, $customerMessage);
            } catch (\Exception $e) {
                \Log::error("Failed to send completed domain SMS to customer {$customer->id}: {$e->getMessage()}");
            }
        }

        if ($reseller->email) {
            $subject = 'Domain registered - '.$domain;
            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new ResellerDomainOrderMail($order, 'completed'),
                $subject,
                NotificationEvent::ResellerDomainPushed,
                $reseller
            );
        }
    }

    public function sendDomainFailedNotification(ResellerDomainOrder $order): void
    {
        $reseller = $order->reseller;
        $domain = $order->fullDomainName();
        $message = "Domain {$domain} registration failed: {$order->failure_reason}";

        try {
            $this->smsService->send($reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send failed domain SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($reseller->email) {
            $subject = 'Domain registration failed - '.$domain;
            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new ResellerDomainOrderMail($order, 'failed'),
                $subject,
                NotificationEvent::ResellerDomainPushed,
                $reseller
            );
        }
    }

    protected function notifyResellerDomainOrder(ResellerDomainOrder $order, NotificationEvent $event, string $variant = 'queued'): void
    {
        $order->loadMissing('reseller', 'customer');
        $reseller = $order->reseller;
        $customer = $order->customer;
        $domain = $order->fullDomainName();
        $walletUrl = route('reseller.wallet.index');

        $smsMessage = match ($event) {
            NotificationEvent::ResellerNewCustomerOrder => "New domain order: {$domain} for {$customer->name}. Customer payment received. Top up your wallet ({$order->wholesale_amount} KES required) and push the order: {$walletUrl}",
            NotificationEvent::ResellerDomainQueued => "Domain {$domain} for customer {$customer->name} is queued. Top up your wallet to process.",
            NotificationEvent::ResellerDomainPushed => "Domain {$domain} for customer {$customer->name} has been pushed to admin. Amount debited: {$order->wholesale_amount} KES",
            default => "Domain order update: {$domain}",
        };

        try {
            $this->smsService->send($reseller->phone, $smsMessage);
        } catch (\Exception $e) {
            \Log::error("Failed to send domain order SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($reseller->email && $this->preferences->isEmailEnabledForUser($reseller, $event)) {
            $company = $this->brandingResolver->forReseller($reseller)['company_name'];
            $this->emailDelivery->sendTemplated($reseller, $event, [
                'reseller_name' => $reseller->name,
                'customer_name' => $customer->name,
                'domain_name' => $domain,
                'wholesale_amount' => number_format((float) $order->wholesale_amount, 2).' KES',
                'wallet_url' => $walletUrl,
                'site_name' => $company,
            ]);
        }
    }

    protected function notifyAdminDomainPush(ResellerDomainOrder $order): void
    {
        $this->notificationService->notifyAdminResellerDomainOrder($order, 'pushed');
    }
}
