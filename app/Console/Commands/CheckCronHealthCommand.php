<?php

namespace App\Console\Commands;

use App\Mail\CronHealthAlertMail;
use App\Models\CronJob;
use App\Models\CronJobLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckCronHealthCommand extends BaseCronCommand
{
    protected $signature = 'cron:check-health';

    protected $description = 'Monitor cron jobs for hung processes and alert if issues detected';

    protected function handleCron(): string
    {
        $defaultMaxExecutionTime = (int) Setting::getValue('max_execution_time', 120);
        $issues = [];

        // Load all still-running logs; apply per-command thresholds below.
        // A global WHERE on max_execution_time would miss long jobs that are fine.
        $runningLogs = CronJobLog::where('status', 'running')
            ->with('cronJob')
            ->get();

        foreach ($runningLogs as $log) {
            $maxExecutionTime = $this->maxRuntimeSeconds($log->cronJob, $defaultMaxExecutionTime);
            $duration = $log->started_at->diffInSeconds(now());

            if ($duration < $maxExecutionTime) {
                continue;
            }

            $issues[] = [
                'type' => 'hung',
                'job' => $log->cronJob,
                'duration' => $duration,
                'max_allowed' => $maxExecutionTime,
            ];

            Log::warning("Hung cron job detected: {$log->cronJob?->name}", [
                'command' => $log->cronJob?->command,
                'started_at' => $log->started_at,
                'duration_seconds' => $duration,
                'max_allowed' => $maxExecutionTime,
            ]);

            if ($duration > ($maxExecutionTime * 2)) {
                $log->update([
                    'status' => 'failed',
                    'exception' => "Job exceeded maximum execution time ({$maxExecutionTime}s) and was marked as failed by health checker",
                    'finished_at' => now(),
                ]);
            }
        }

        $recentFails = CronJobLog::whereIn('status', ['failed'])
            ->where('started_at', '>=', now()->subHours(1))
            ->with('cronJob')
            ->get()
            ->groupBy('cron_job_id');

        foreach ($recentFails as $logs) {
            if ($logs->count() >= 3) {
                $job = $logs->first()->cronJob;
                Log::critical("Cron job has failed 3+ times in last hour: {$job->name}", [
                    'command' => $job->command,
                    'failure_count' => $logs->count(),
                ]);

                $issues[] = [
                    'type' => 'consecutive_failures',
                    'job' => $job,
                    'count' => $logs->count(),
                ];
            }
        }

        if (! empty($issues)) {
            $this->alertAdminOfIssues($issues);
        }

        return empty($issues)
            ? 'Cron health check passed — no issues detected.'
            : 'Cron health check found '.count($issues).' issue(s). Alerts sent.';
    }

    private function alertAdminOfIssues(array $issues): void
    {
        $bridge = app(TelegramMonitorBridge::class);

        foreach ($issues as $issue) {
            if ($issue['type'] === 'hung') {
                $bridge->systemAlert('Cron job hung', [
                    'Job' => $issue['job']->name,
                    'Command' => $issue['job']->command,
                    'Running for' => $issue['duration'].' seconds',
                    'Max allowed' => ($issue['max_allowed'] ?? '?').' seconds',
                ]);
            } elseif ($issue['type'] === 'consecutive_failures') {
                $bridge->systemAlert('Cron job failing repeatedly', [
                    'Job' => $issue['job']->name,
                    'Command' => $issue['job']->command,
                    'Failures (1h)' => (string) $issue['count'],
                ]);
            }
        }

        try {
            $admins = User::where('is_admin', true)->get();

            foreach ($admins as $admin) {
                Mail::to($admin->email)->queue(
                    new CronHealthAlertMail($issues)
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to send cron health alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve how long a job may stay "running" before it is treated as hung.
     */
    public function maxRuntimeSeconds(?CronJob $job, ?int $default = null): int
    {
        $default ??= (int) Setting::getValue('max_execution_time', 120);
        $command = $job?->command;

        if ($command) {
            $override = (int) config('cron.hang_thresholds.'.$command, 0);
            if ($override > 0) {
                return max($default, $override);
            }
        }

        return max(30, $default);
    }
}
