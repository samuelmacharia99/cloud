<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;

class PruneContainerMetricsCommand extends BaseCronCommand
{
    protected $signature = 'cron:prune-container-metrics {--days=90 : Delete metrics older than this many days}';

    protected $description = 'Delete container metric samples older than the retention window';

    protected function handleCron(): string
    {
        $days = max(7, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = DB::table('container_metrics')
            ->where('recorded_at', '<', $cutoff)
            ->delete();

        return "Pruned {$deleted} container metric rows older than {$days} days.";
    }
}
