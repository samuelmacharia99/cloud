<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\CronJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Load all enabled cron jobs from database and schedule them dynamically
        $cronJobs = CronJob::where('enabled', true)->get();

        foreach ($cronJobs as $job) {
            $scheduledCommand = $schedule->command($job->command)
                ->cron($job->schedule)
                ->name($job->name)
                ->onOneServer(); // Prevent duplicate runs on multiple servers

            // Add failure callback to log failures and notify admin
            $scheduledCommand->onFailure(function () use ($job) {
                $this->logCronFailure($job);
            });
        }

        // Always run the cron health check frequently to detect hung jobs
        $schedule->command('cron:check-health')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(5)
            ->name('Cron Health Check');

        // Timezone is set by application
        $schedule->timezone(\App\Models\Setting::getValue('cron_timezone', 'UTC'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Log cron failures and alert admins
     */
    private function logCronFailure(CronJob $job): void
    {
        try {
            $job->update(['last_status' => 'failed']);

            // Notify administrators
            $admins = \App\Models\User::where('is_admin', true)->get();
            foreach ($admins as $admin) {
                // Send email alert
                \Illuminate\Support\Facades\Mail::to($admin->email)->send(
                    new \App\Mail\CronFailureMail($job)
                );
            }

            \Illuminate\Support\Facades\Log::critical("Cron job '{$job->name}' failed", [
                'command' => $job->command,
                'schedule' => $job->schedule,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to log cron failure', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);
        }
    }
}
