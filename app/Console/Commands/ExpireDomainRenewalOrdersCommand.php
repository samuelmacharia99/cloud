<?php

namespace App\Console\Commands;

use App\Models\DomainRenewalOrder;
use App\Services\DomainRenewalService;
use Illuminate\Console\Command;

class ExpireDomainRenewalOrdersCommand extends Command
{
    protected $signature = 'cron:expire-domain-renewal-orders';

    protected $description = 'Expire pending domain renewal orders that have passed their expiration window';

    public function handle()
    {
        $renewalService = new DomainRenewalService();
        $expiredOrders = DomainRenewalOrder::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredOrders as $order) {
            $renewalService->expireRenewal($order);
        }

        if ($expiredOrders->count() > 0) {
            $this->info("Expired {$expiredOrders->count()} domain renewal orders");
        } else {
            $this->info('No domain renewal orders to expire');
        }

        return Command::SUCCESS;
    }
}
