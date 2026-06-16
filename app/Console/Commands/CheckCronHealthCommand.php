<?php

namespace App\Console\Commands;

use App\Mail\CronHealthAlertMail;
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
        $maxExecutionTime = (int) Setting::getValue('max_execution_time', 120);
        $issues = [];

        $hungjobs = CronJobLog::where('status', 'running')
            ->where('started_at', '<', now()->subSeconds($maxExecutionTime))
            ->with('cronJob')
            ->get();

        foreach ($hungjobs as $log) {
            $issues[] = [
                'type' => 'hung',
                'job' => $log->cronJob,
                'duration' => $log->started_at->diffInSeconds(now()),
            ];

            Log::warning("Hung cron job detected: {$log->cronJob->name}", [
                'started_at' => $log->started_at,
                'duration_seconds' => $log->started_at->diffInSeconds(now()),
                'max_allowed' => $maxExecutionTime,
            ]);

            if ($log->started_at->diffInSeconds(now()) > ($maxExecutionTime * 2)) {
                $log->update([
                    'status' => 'failed',
                    'exception' => 'Job exceeded maximum execution time and was marked as failed by health checker',
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
}
