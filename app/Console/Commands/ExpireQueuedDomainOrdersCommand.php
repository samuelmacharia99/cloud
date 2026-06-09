<?php

namespace App\Console\Commands;

use App\Models\ResellerDomainOrder;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ExpireQueuedDomainOrdersCommand extends Command
{
    protected $signature = 'cron:expire-queued-domain-orders';

    protected $description = 'Mark queued domain orders as expired if past their expiration date';

    public function handle(): int
    {
        $expiring = ResellerDomainOrder::query()
            ->where('status', 'queued')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No queued domain orders to expire.');

            return self::SUCCESS;
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
        $this->info("{$total} domain order(s) expired for ".$countsByReseller->count().' reseller(s)');

        return self::SUCCESS;
    }
}
