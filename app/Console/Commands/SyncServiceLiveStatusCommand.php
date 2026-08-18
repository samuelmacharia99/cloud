<?php

namespace App\Console\Commands;

use App\Services\ServiceStatusSyncService;

class SyncServiceLiveStatusCommand extends BaseCronCommand
{
    protected $signature = 'cron:sync-service-live-status
        {--limit=100 : Max services to check per run}
        {--heal : Heal obvious provisioning drift}
        {--max-runtime= : Wall-clock seconds before stopping new probes}';

    protected $description = 'Probe DirectAdmin and container services for live infrastructure status';

    protected function handleCron(): string
    {
        $sync = app(ServiceStatusSyncService::class);
        $limit = max(1, (int) $this->option('limit'));
        $heal = (bool) $this->option('heal');
        $maxRuntime = $this->resolveMaxRuntimeSeconds();
        $deadline = $this->startTime->copy()->addSeconds($maxRuntime);

        $services = $sync->pollableQuery()
            ->orderByRaw('live_status_checked_at IS NOT NULL, live_status_checked_at ASC')
            ->limit($limit)
            ->get();

        if ($services->isEmpty()) {
            return 'No provisionable services to check.';
        }

        $summary = $sync->syncMany($services, $heal, $deadline);

        $message = sprintf(
            'Checked %d services (%d mismatches, %d probe errors).',
            $summary['checked'],
            $summary['mismatches'],
            $summary['errors']
        );

        if (($summary['deferred'] ?? 0) > 0) {
            $message .= sprintf(' Deferred %d to the next run after %ds.', $summary['deferred'], $maxRuntime);
        }

        return $message;
    }

    private function resolveMaxRuntimeSeconds(): int
    {
        $option = $this->option('max-runtime');
        if ($option !== null && $option !== '') {
            return max(1, (int) $option);
        }

        return max(30, (int) config('cron.live_status.max_runtime_seconds', 240));
    }
}
