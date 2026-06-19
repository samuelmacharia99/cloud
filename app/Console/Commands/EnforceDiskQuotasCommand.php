<?php

namespace App\Console\Commands;

use App\Services\Hosting\ServicePackageLimitEnforcementService;

class EnforceDiskQuotasCommand extends BaseCronCommand
{
    protected $signature = 'cron:enforce-disk-quotas';

    protected $description = 'Suspends shared hosting accounts over package limits (disk, bandwidth, databases) and restores them when usage drops';

    protected function handleCron(): string
    {
        $enforcement = app(ServicePackageLimitEnforcementService::class);

        if (! $enforcement->isEnabled()) {
            return 'Package limit enforcement skipped: suspend_on_package_overquota is disabled.';
        }

        $result = $enforcement->enforce();

        $message = "Suspended {$result['suspended']} service(s) for package limit exceeded";
        if ($result['restored'] > 0) {
            $message .= ", restored {$result['restored']}";
        }
        if ($result['skipped'] > 0) {
            $message .= " ({$result['skipped']} skipped)";
        }

        return $message.' (includes reseller-managed customers; disk, bandwidth, and database limits).';
    }
}
