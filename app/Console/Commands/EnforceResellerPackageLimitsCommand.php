<?php

namespace App\Console\Commands;

use App\Services\ResellerEnforcementService;
use Illuminate\Support\Facades\Log;

class EnforceResellerPackageLimitsCommand extends BaseCronCommand
{
    protected $signature = 'cron:enforce-reseller-package-limits';

    protected $description = 'Suspends resellers and services that exceed package specs (service slots, disk pool, user limits)';

    protected function handleCron(): string
    {
        $enforcement = app(ResellerEnforcementService::class);

        if (! $enforcement->isExcessEnforcementEnabled()
            && ! $enforcement->isDiskPoolSuspensionEnabled()
            && ! $enforcement->isUserLimitSuspensionEnabled()) {
            return 'Reseller package limit enforcement skipped: all reseller auto-suspend settings are disabled.';
        }

        try {
            $result = $enforcement->enforceAllPackageLimits();
        } catch (\Throwable $e) {
            Log::error('Reseller package limit enforcement failed: '.$e->getMessage());

            throw $e;
        }

        return "Suspended {$result['suspended']} reseller account(s) for package spec violations, "
            ."restored {$result['restored']}, suspended {$result['serviceSlots']} excess service slot(s).";
    }
}
