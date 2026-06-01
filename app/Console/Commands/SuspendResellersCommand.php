<?php

namespace App\Console\Commands;

use App\Services\ResellerEnforcementService;
use Illuminate\Support\Facades\Log;

class SuspendResellersCommand extends BaseCronCommand
{
    protected $signature = 'cron:suspend-resellers';

    protected $description = 'Suspends resellers with overdue or expired package subscriptions';

    protected function handleCron(): string
    {
        $enforcement = app(ResellerEnforcementService::class);

        if (! $enforcement->isSuspensionEnabled()) {
            return 'Reseller suspension skipped: reseller_suspend_on_overdue is disabled.';
        }

        $resellers = $enforcement->resellersEligibleForSuspensionQuery()->get();
        $suspended = 0;
        $cascadeCount = 0;

        foreach ($resellers as $reseller) {
            if (! $enforcement->shouldSuspendReseller($reseller)) {
                continue;
            }

            try {
                $cascadeCount += $enforcement->suspendReseller($reseller);
                $suspended++;
            } catch (\Throwable $e) {
                Log::error("Failed to suspend reseller {$reseller->id}: {$e->getMessage()}");
            }
        }

        return "Suspended {$suspended} reseller account(s); {$cascadeCount} managed service(s) suspended on DirectAdmin.";
    }
}
