<?php

namespace App\Console\Scheduling;

use App\Jobs\RunPlatformCronJob;
use App\Jobs\SendCronFailureAlerts;
use App\Models\CronJob;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ApplicationSchedule
{
    public function configure(Schedule $schedule): void
    {
        if (! config('scheduler.enabled')) {
            return;
        }

        $schedule->timezone(Setting::getValue('cron_timezone', 'UTC'));
        $this->registerDatabaseJobs($schedule);
        $this->registerHeartbeat($schedule);
    }

    private function touchHeartbeat(): void
    {
        $now = now()->toIso8601String();
        Cache::put('scheduler.last_heartbeat', $now, now()->addMinutes(5));
        Setting::setValue('scheduler_last_heartbeat_at', $now);
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

            if ($this->isRetiredCommand($job->command)) {
                $this->disableMissingCommand($job);

                continue;
            }

            if (! $job->next_run_at) {
                $job->refreshNextRunAt();
            }

            $overlapMinutes = $this->overlapExpiresMinutes($job->command);

            $event = $schedule->call(function () use ($job): void {
                RunPlatformCronJob::dispatch($job->id);
            })
                ->cron($job->schedule)
                ->name("{$job->name} [{$job->command}]")
                ->withoutOverlapping($overlapMinutes);

            if (config('scheduler.use_on_one_server')) {
                $event->onOneServer();
            }

            $event->onFailure(function () use ($job) {
                $this->logCronFailure($job);
            });
        }
    }

    private function isRetiredCommand(string $commandLine): bool
    {
        $name = trim(explode(' ', $commandLine)[0] ?? '');

        return $name !== '' && in_array($name, config('scheduler.retired_commands', []), true);
    }

    private function disableMissingCommand(CronJob $job): void
    {
        if (! $job->enabled) {
            return;
        }

        $job->update(['enabled' => false]);

        Log::warning('Disabled cron job because artisan command was retired/removed', [
            'cron_job_id' => $job->id,
            'name' => $job->name,
            'command' => $job->command,
        ]);
    }

    private function registerHeartbeat(Schedule $schedule): void
    {
        $heartbeat = $schedule->call(function () {
            $this->touchHeartbeat();
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

    /**
     * Minutes before Laravel releases the withoutOverlapping mutex.
     */
    public function overlapExpiresMinutes(string $commandLine): int
    {
        $command = trim(explode(' ', $commandLine)[0] ?? '');
        $configured = (int) config('cron.overlap_expires_minutes.'.$command, 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(1, (int) config('cron.overlap_expires_minutes.default', 60));
    }

    private function logCronFailure(CronJob $job): void
    {
        try {
            $job->update(['last_status' => 'failed']);
            SendCronFailureAlerts::dispatch($job->id);

            Log::critical("Cron job '{$job->name}' failed", [
                'command' => $job->command,
                'schedule' => $job->schedule,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log cron failure', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);
        }
    }
}
