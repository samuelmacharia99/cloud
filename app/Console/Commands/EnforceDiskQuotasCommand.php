<?php

namespace App\Console\Commands;

use App\Services\ServiceDiskQuotaEnforcementService;

class EnforceDiskQuotasCommand extends BaseCronCommand
{
    protected $signature = 'cron:enforce-disk-quotas';

    protected $description = 'Suspends DirectAdmin hosting accounts that exceed disk quota and restores them when usage drops';

    protected function handleCron(): string
    {
        $enforcement = app(ServiceDiskQuotaEnforcementService::class);

        if (! $enforcement->isEnabled()) {
            return 'Disk quota enforcement skipped: suspend_on_disk_overquota is disabled.';
        }

        $result = $enforcement->enforce();

        $message = "Suspended {$result['suspended']} service(s) for disk overquota";
        if ($result['restored'] > 0) {
            $message .= ", restored {$result['restored']}";
        }
        if ($result['skipped'] > 0) {
            $message .= " ({$result['skipped']} skipped)";
        }

        return $message.' (includes reseller-managed customers).';
    }
}
