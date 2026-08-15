<?php

namespace App\Jobs;

use App\Models\CronJob;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class RunPlatformCronJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(public int $cronJobId)
    {
        $job = CronJob::query()->find($cronJobId);
        $commandName = trim(explode(' ', (string) $job?->command)[0] ?? '');
        $runtime = max(
            (int) Setting::getValue('max_execution_time', 120),
            (int) config('cron.hang_thresholds.'.$commandName, 0)
        );

        $this->timeout = $runtime + 60;
        $this->uniqueFor = max(
            $this->timeout + 60,
            (int) config('cron.overlap_expires_minutes.'.$commandName, 60) * 60
        );
        $this->onQueue('platform-cron');
    }

    public function uniqueId(): string
    {
        return 'platform-cron:'.$this->cronJobId;
    }

    public function handle(): void
    {
        $job = CronJob::query()->find($this->cronJobId);
        if (! $job || ! $job->enabled) {
            return;
        }

        $exitCode = Artisan::call($job->command);
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Cron command '{$job->command}' exited with status {$exitCode}: "
                .trim(Artisan::output())
            );
        }
    }

    public function failed(?\Throwable $error): void
    {
        $job = CronJob::query()->find($this->cronJobId);
        if (! $job) {
            return;
        }

        $job->update(['last_status' => 'failed']);
        SendCronFailureAlerts::dispatch($job->id);
    }
}
