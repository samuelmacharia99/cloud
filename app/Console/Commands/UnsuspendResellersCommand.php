<?php

namespace App\Console\Commands;

use App\Services\ResellerEnforcementService;
use Illuminate\Support\Facades\Log;

class UnsuspendResellersCommand extends BaseCronCommand
{
    protected $signature = 'cron:unsuspend-resellers';

    protected $description = 'Restores resellers whose package subscription is current and unsuspends cascade-suspended services';

    protected function handleCron(): string
    {
        $enforcement = app(ResellerEnforcementService::class);

        $resellers = $enforcement->resellersEligibleForUnsuspensionQuery()->get();
        $restored = 0;
        $cascadeCount = 0;

        foreach ($resellers as $reseller) {
            if (! $enforcement->resellerBillingIsCurrent($reseller)) {
                continue;
            }

            try {
                $cascadeCount += $enforcement->unsuspendReseller($reseller);
                $restored++;
            } catch (\Throwable $e) {
                Log::error("Failed to unsuspend reseller {$reseller->id}: {$e->getMessage()}");
            }
        }

        return "Restored {$restored} reseller account(s); {$cascadeCount} managed service(s) unsuspended on DirectAdmin.";
    }
}
