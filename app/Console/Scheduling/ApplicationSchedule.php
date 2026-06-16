<?php

namespace App\Console\Scheduling;

use App\Mail\CronFailureMail;
use App\Models\CronJob;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class ApplicationSchedule
{
    public function configure(Schedule $schedule): void
    {
        if (! config('scheduler.enabled')) {
            return;
        }

        $this->registerDatabaseJobs($schedule);
        $this->registerHeartbeat($schedule);

        $schedule->timezone(Setting::getValue('cron_timezone', 'UTC'));
    }

    private function registerDatabaseJobs(Schedule $schedule): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        try {
            $jobs = CronJob::where('enabled', true)->get();
        } catch (\Throwable $e) {
            Log::debug('Cron jobs not loaded: '.$e->getMessage());

            return;
        }

        foreach ($jobs as $job) {
            if (! $this->shouldRunJob($job)) {
                continue;
            }

            if (! $job->next_run_at) {
                $job->refreshNextRunAt();
            }

            $event = $schedule->command($job->command)
                ->cron($job->schedule)
                ->name($job->name)
                ->withoutOverlapping(10);

            if (config('scheduler.use_on_one_server')) {
                $event->onOneServer();
            }

            $event->onFailure(function () use ($job) {
                $this->logCronFailure($job);
            });
        }
    }

    private function registerHeartbeat(Schedule $schedule): void
    {
        $heartbeat = $schedule->call(function () {
            Cache::put('scheduler.last_heartbeat', now()->toIso8601String(), now()->addMinutes(5));
        })
            ->everyMinute()
            ->name('Scheduler Heartbeat')
            ->withoutOverlapping(1);

        if (config('scheduler.use_on_one_server')) {
            $heartbeat->onOneServer();
        }
    }

    private function shouldRunJob(CronJob $job): bool
    {
        if (! app()->environment('local')) {
            return true;
        }

        return ! in_array($job->command, config('scheduler.skip_in_local', []), true);
    }

    private function logCronFailure(CronJob $job): void
    {
        try {
            $job->update(['last_status' => 'failed']);

            $admins = User::where('is_admin', true)->get();
            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(
                    new CronFailureMail($job)
                );
            }

            Log::critical("Cron job '{$job->name}' failed", [
                'command' => $job->command,
                'schedule' => $job->schedule,
            ]);

            app(TelegramMonitorBridge::class)->systemAlert('Cron job failed', [
                'Job' => $job->name,
                'Command' => $job->command,
                'Schedule' => $job->schedule,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log cron failure', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);
        }
    }
}
