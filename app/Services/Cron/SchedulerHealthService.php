<?php

namespace App\Services\Cron;

use App\Helpers\CronHelper;
use App\Models\CronJob;
use App\Models\CronJobLog;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SchedulerHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $schedulerEnabled = (bool) config('scheduler.enabled');
        $heartbeatRaw = Cache::get('scheduler.last_heartbeat');
        $heartbeatAt = $heartbeatRaw ? Carbon::parse($heartbeatRaw) : null;
        $heartbeatFresh = $heartbeatAt && $heartbeatAt->greaterThan(now()->subMinutes(3));

        $enabledJobs = CronJob::where('enabled', true)->count();
        $staleNextRun = CronJob::where('enabled', true)
            ->whereNull('next_run_at')
            ->count();

        $recentRuns = CronJobLog::where('started_at', '>=', now()->subHours(24))->count();
        $recentFailures = CronJobLog::where('started_at', '>=', now()->subHours(24))
            ->where('status', 'failed')
            ->count();

        $lastLogRun = CronJobLog::query()->latest('started_at')->value('started_at');

        $issues = [];

        if (! $schedulerEnabled) {
            $issues[] = 'Laravel scheduler is disabled (SCHEDULER_ENABLED=false).';
        }

        if ($schedulerEnabled && ! $heartbeatFresh) {
            $issues[] = 'No scheduler heartbeat in the last 3 minutes — OS cron or systemd timer may not be running schedule:run.';
        }

        if ($enabledJobs === 0) {
            $issues[] = 'No cron jobs are enabled in the database.';
        }

        if ($staleNextRun > 0) {
            $issues[] = "{$staleNextRun} enabled job(s) are missing next run time — run php artisan cron:refresh-schedules.";
        }

        $cronValidation = CronHelper::validateCronSetup();

        return [
            'scheduler_enabled' => $schedulerEnabled,
            'heartbeat_at' => $heartbeatAt?->toIso8601String(),
            'heartbeat_fresh' => $heartbeatFresh,
            'enabled_jobs' => $enabledJobs,
            'stale_next_run_count' => $staleNextRun,
            'recent_runs_24h' => $recentRuns,
            'recent_failures_24h' => $recentFailures,
            'last_logged_run_at' => $lastLogRun ? Carbon::parse($lastLogRun)->toIso8601String() : null,
            'cron_timezone' => Setting::getValue('cron_timezone', config('app.timezone')),
            'cron_command' => CronHelper::generateCronCommand(),
            'validation' => $cronValidation,
            'healthy' => $schedulerEnabled && $heartbeatFresh && $enabledJobs > 0 && ($cronValidation['valid'] ?? false),
            'issues' => $issues,
        ];
    }
}
