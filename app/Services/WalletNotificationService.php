<?php

namespace App\Services;

use App\Models\ResellerDomainOrder;
use App\Models\ResellerWallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Mail;

class WalletNotificationService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function sendLowBalanceAlert(ResellerWallet $wallet): void
    {
        if (! $wallet->needsLowBalanceAlert()) {
            return;
        }

        $reseller = $wallet->reseller;
        $company = $this->brandingResolver->forReseller($reseller)['company_name'];
        $message = "Your {$company} wallet balance is low: {$wallet->getFormattedBalance()}. Top up now: ".route('reseller.wallet.index');

        try {
            app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send low balance SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }

        if ($reseller->email) {
            try {
                Mail::raw(
                    "Your wallet balance is low: {$wallet->getFormattedBalance()}\n\nTop up now at: ".route('reseller.wallet.index'),
                    function ($message) use ($reseller, $company) {
                        $message->to($reseller->email)
                            ->subject($company.' Wallet Low Balance Alert');
                    }
                );
            } catch (\Exception $e) {
                \Log::error("Failed to send low balance email to reseller {$reseller->id}: {$e->getMessage()}");
            }
        }

        $wallet->update(['last_low_balance_alert_at' => now()]);
    }

    public function sendTopupConfirmation(WalletTransaction $transaction): void
    {
        $reseller = $transaction->wallet->reseller;
        $message = "Wallet top-up confirmed! Amount: {$transaction->amount} KES. New balance: {$transaction->balance_after} KES";

        try {
            app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send topup SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }
    }

    public function sendDomainQueuedNotification(ResellerDomainOrder $order): void
    {
        $reseller = $order->reseller;
        $customer = $order->customer;
        $message = "Domain {$order->domain_name}.{$order->extension} for customer {$customer->name} is queued. Top up your wallet to process.";

        try {
            app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send queued domain SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }
    }

    public function sendDomainPushedNotification(ResellerDomainOrder $order): void
    {
        $reseller = $order->reseller;
        $customer = $order->customer;
        $message = "Domain {$order->domain_name}.{$order->extension} for customer {$customer->name} has been pushed to admin. Amount debited: {$order->wholesale_amount} KES";

        try {
            app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send pushed domain SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }
    }

    public function sendDomainCompletedNotification(ResellerDomainOrder $order): void
    {
        $reseller = $order->reseller;
        $customer = $order->customer;
        $company = $this->brandingResolver->forReseller($reseller)['company_name'];

        $resellerMessage = "Domain {$order->domain_name}.{$order->extension} has been registered successfully!";
        $customerMessage = "{$company}: Domain {$order->domain_name}.{$order->extension} has been registered successfully!";

        try {
            app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $resellerMessage);
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
    }

    public function sendDomainFailedNotification(ResellerDomainOrder $order): void
    {
        $reseller = $order->reseller;
        $message = "Domain {$order->domain_name}.{$order->extension} registration failed: {$order->failure_reason}";

        try {
            app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $message);
        } catch (\Exception $e) {
            \Log::error("Failed to send failed domain SMS to reseller {$reseller->id}: {$e->getMessage()}");
        }
    }
}
