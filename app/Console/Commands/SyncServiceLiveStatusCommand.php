<?php

namespace App\Console\Commands;

use App\Services\ServiceStatusSyncService;

class SyncServiceLiveStatusCommand extends BaseCronCommand
{
    protected $signature = 'cron:sync-service-live-status {--limit=100 : Max services to check per run} {--heal : Heal obvious provisioning drift}';

    protected $description = 'Probe DirectAdmin and container services for live infrastructure status';

    protected function handleCron(): string
    {
        $sync = app(ServiceStatusSyncService::class);
        $limit = max(1, (int) $this->option('limit'));
        $heal = (bool) $this->option('heal');

        $services = $sync->pollableQuery()
            ->orderByRaw('live_status_checked_at IS NOT NULL, live_status_checked_at ASC')
            ->limit($limit)
            ->get();

        if ($services->isEmpty()) {
            return 'No provisionable services to check.';
        }

        $summary = $sync->syncMany($services, $heal);

        return sprintf(
            'Checked %d services (%d mismatches, %d probe errors).',
            $summary['checked'],
            $summary['mismatches'],
            $summary['errors']
        );
    }
}
