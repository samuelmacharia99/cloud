<?php

namespace App\Console\Commands;

use App\Models\ResellerWallet;
use App\Services\WalletNotificationService;
use Illuminate\Console\Command;

class WalletLowBalanceAlertsCommand extends Command
{
    protected $signature = 'cron:wallet-low-balance-alerts';
    protected $description = 'Send low balance alerts for reseller wallets that have insufficient funds';

    public function handle(WalletNotificationService $notificationService): int
    {
        $wallets = ResellerWallet::where('balance', '<', \DB::raw('low_balance_threshold'))
            ->where('status', 'active')
            ->get();

        $alertsSent = 0;
        $alreadyNotified = 0;

        foreach ($wallets as $wallet) {
            if ($wallet->needsLowBalanceAlert()) {
                try {
                    $notificationService->sendLowBalanceAlert($wallet);
                    $alertsSent++;
                } catch (\Exception $e) {
                    $this->error("Failed to send alert for reseller {$wallet->reseller_id}: {$e->getMessage()}");
                }
            } else {
                $alreadyNotified++;
            }
        }

        $message = "{$alertsSent} low balance alert(s) sent, {$alreadyNotified} already notified within 24h";
        $this->info($message);

        return self::SUCCESS;
    }
}
