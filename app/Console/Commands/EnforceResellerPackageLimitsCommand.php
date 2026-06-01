<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ResellerEnforcementService;
use Illuminate\Support\Facades\Log;

class EnforceResellerPackageLimitsCommand extends BaseCronCommand
{
    protected $signature = 'cron:enforce-reseller-package-limits';

    protected $description = 'Suspends excess active services when resellers exceed package service slot limits';

    protected function handleCron(): string
    {
        $enforcement = app(ResellerEnforcementService::class);

        if (! $enforcement->isExcessEnforcementEnabled()) {
            return 'Reseller package limit enforcement skipped: reseller_suspend_excess_services is disabled.';
        }

        $resellers = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->get();

        $total = 0;

        foreach ($resellers as $reseller) {
            try {
                $total += $enforcement->enforcePackageLimitsForReseller($reseller);
            } catch (\Throwable $e) {
                Log::error("Reseller package limit enforcement failed for {$reseller->id}: {$e->getMessage()}");
            }
        }

        return "Suspended {$total} excess service(s) across reseller accounts.";
    }
}
