<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DomainRenewalPushService;

class ProcessQueuedDomainRenewalsCommand extends BaseCronCommand
{
    protected $signature = 'cron:process-queued-domain-renewals';

    protected $description = 'Push queued domain renewals when resellers have sufficient wallet funds';

    protected function handleCron(): string
    {
        $pushService = app(DomainRenewalPushService::class);

        $resellers = User::query()
            ->where('is_reseller', true)
            ->whereHas('resellerDomainRenewalOrders', function ($query) {
                $query->where('status', 'queued')
                    ->where('expires_at', '>', now());
            })
            ->get();

        $totalPushed = 0;
        $resellersProcessed = 0;

        foreach ($resellers as $reseller) {
            $pushed = $pushService->processQueuedRenewals($reseller);
            $totalPushed += $pushed;
            if ($pushed > 0) {
                $resellersProcessed++;
            }
        }

        return "{$totalPushed} domain renewal(s) pushed from {$resellersProcessed} reseller(s)";
    }
}
