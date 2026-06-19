<?php

namespace App\Console\Commands;

use App\Services\Hosting\ServicePackageUsageWarningService;

class CheckHostingPackageUsageCommand extends BaseCronCommand
{
    protected $signature = 'cron:check-hosting-package-usage';

    protected $description = 'Checks shared hosting disk, bandwidth, and database usage; notifies customers at 90% to upgrade';

    protected function handleCron(): string
    {
        $result = app(ServicePackageUsageWarningService::class)->run();

        return "Checked {$result['checked']} service(s), {$result['at_risk']} at or above warning threshold, "
            ."{$result['notified']} notified, {$result['skipped']} skipped.";
    }
}
