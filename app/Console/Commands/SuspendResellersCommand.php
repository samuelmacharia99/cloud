<?php

namespace App\Console\Commands;

use App\Models\User;
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

        $retried = 0;
        $failedSyncResellers = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_suspended_at')
            ->whereNotNull('reseller_directadmin_sync_failed_at')
            ->get();

        foreach ($failedSyncResellers as $reseller) {
            if ($enforcement->retryDirectAdminSuspendIfNeeded($reseller)) {
                $retried++;
            }
        }

        $message = "Suspended {$suspended} reseller account(s); {$cascadeCount} managed service(s) suspended on DirectAdmin.";

        if ($retried > 0) {
            $message .= " Retried DirectAdmin sync for {$retried} reseller account(s).";
        }

        return $message;
    }
}
