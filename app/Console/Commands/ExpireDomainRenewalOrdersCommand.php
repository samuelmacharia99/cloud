<?php

namespace App\Console\Commands;

use App\Models\DomainRenewalOrder;
use App\Services\DomainRenewalService;

class ExpireDomainRenewalOrdersCommand extends BaseCronCommand
{
    protected $signature = 'cron:expire-domain-renewal-orders';

    protected $description = 'Expire pending domain renewal orders that have passed their expiration window';

    protected function handleCron(): string
    {
        $renewalService = new DomainRenewalService;
        $expiredOrders = DomainRenewalOrder::whereIn('status', ['pending', 'invoiced'])
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredOrders as $order) {
            $renewalService->expireRenewal($order);
        }

        if ($expiredOrders->count() > 0) {
            return "Expired {$expiredOrders->count()} domain renewal orders";
        }

        return 'No domain renewal orders to expire';
    }
}
