<?php

namespace App\Console\Commands;

use App\Models\ResellerDomainOrder;
use App\Models\User;
use App\Services\NotificationService;

class ExpireQueuedDomainOrdersCommand extends BaseCronCommand
{
    protected $signature = 'cron:expire-queued-domain-orders';

    protected $description = 'Mark queued domain orders as expired if past their expiration date';

    protected function handleCron(): string
    {
        $expiring = ResellerDomainOrder::query()
            ->where('status', 'queued')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiring->isEmpty()) {
            return 'No queued domain orders to expire.';
        }

        $countsByReseller = $expiring->groupBy('reseller_id')->map->count();

        ResellerDomainOrder::query()
            ->whereIn('id', $expiring->pluck('id'))
            ->update(['status' => 'expired']);

        $notifications = app(NotificationService::class);

        foreach ($countsByReseller as $resellerId => $count) {
            $reseller = User::query()->find($resellerId);
            if ($reseller) {
                $notifications->notifyResellerDomainOrdersExpired($reseller, (int) $count);
            }
        }

        $total = $expiring->count();

        return "{$total} domain order(s) expired for ".$countsByReseller->count().' reseller(s)';
    }
}
