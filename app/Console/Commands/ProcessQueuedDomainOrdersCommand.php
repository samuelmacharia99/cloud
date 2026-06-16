<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DomainPushService;

class ProcessQueuedDomainOrdersCommand extends BaseCronCommand
{
    protected $signature = 'cron:process-queued-domain-orders';

    protected $description = 'Process queued domain orders when resellers have sufficient funds';

    protected function handleCron(): string
    {
        $domainPushService = app(DomainPushService::class);

        $resellers = User::where('is_reseller', true)
            ->whereHas('domainOrders', function ($query) {
                $query->where('status', 'queued')
                    ->where('expires_at', '>', now());
            })
            ->get();

        $totalPushed = 0;
        $resellersProcessed = 0;

        foreach ($resellers as $reseller) {
            $pushed = $domainPushService->processQueuedOrders($reseller);
            $totalPushed += $pushed;
            if ($pushed > 0) {
                $resellersProcessed++;
            }
        }

        return "{$totalPushed} domain order(s) pushed from {$resellersProcessed} reseller(s)";
    }
}
