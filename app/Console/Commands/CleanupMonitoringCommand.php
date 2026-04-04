<?php

namespace App\Console\Commands;

use App\Models\NodeMonitoring;
use App\Models\CronJobLog;
use App\Models\Setting;

class CleanupMonitoringCommand extends BaseCronCommand
{
    protected $signature = 'cron:cleanup-monitoring';
    protected $description = 'Deletes node monitoring records and old cron logs older than retention period';

    protected function handleCron(): string
    {
        $retentionDays = (int) Setting::getValue('cron_retention_days', 30);

        $deleted = NodeMonitoring::where('recorded_at', '<', now()->subDays($retentionDays))
            ->delete();

        $logsDeleted = CronJobLog::where('started_at', '<', now()->subDays($retentionDays))
            ->delete();

        return "Deleted {$deleted} node monitoring record(s) and {$logsDeleted} old cron log(s) older than {$retentionDays} days.";
    }
}
