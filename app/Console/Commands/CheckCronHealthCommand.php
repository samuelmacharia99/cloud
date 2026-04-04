<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronJobLog;
use Illuminate\Console\Command;

class CheckCronHealthCommand extends Command
{
    protected $signature = 'cron:check-health';
    protected $description = 'Monitor cron jobs for hung processes and alert if issues detected';

    public function handle(): int
    {
        $maxExecutionTime = (int) \App\Models\Setting::getValue('max_execution_time', 120);
        $issues = [];

        // Check for hung jobs (still running but exceeded max execution time)
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

            // Log the hung job
            \Illuminate\Support\Facades\Log::warning("Hung cron job detected: {$log->cronJob->name}", [
                'started_at' => $log->started_at,
                'duration_seconds' => $log->started_at->diffInSeconds(now()),
                'max_allowed' => $maxExecutionTime,
            ]);

            // Mark job as failed if it's been running too long
            if ($log->started_at->diffInSeconds(now()) > ($maxExecutionTime * 2)) {
                $log->update([
                    'status' => 'failed',
                    'exception' => 'Job exceeded maximum execution time and was marked as failed by health checker',
                    'finished_at' => now(),
                ]);
            }
        }

        // Check for recently failed jobs and alert if consecutive failures
        $recentFails = CronJobLog::whereIn('status', ['failed'])
            ->where('started_at', '>=', now()->subHours(1))
            ->with('cronJob')
            ->get()
            ->groupBy('cron_job_id');

        foreach ($recentFails as $jobId => $logs) {
            if ($logs->count() >= 3) {
                $job = $logs->first()->cronJob;
                \Illuminate\Support\Facades\Log::critical("Cron job has failed 3+ times in last hour: {$job->name}", [
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

        // Alert admin if issues found
        if (!empty($issues)) {
            $this->alertAdminOfIssues($issues);
        }

        return self::SUCCESS;
    }

    private function alertAdminOfIssues(array $issues): void
    {
        try {
            $admins = \App\Models\User::where('is_admin', true)->get();

            foreach ($admins as $admin) {
                \Illuminate\Support\Facades\Mail::to($admin->email)->queue(
                    new \App\Mail\CronHealthAlertMail($issues)
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send cron health alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
