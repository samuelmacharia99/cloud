<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronJobLog;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

abstract class BaseCronCommand extends Command
{
    protected ?CronJob $cronJob = null;

    protected ?CronJobLog $cronLog = null;

    protected Carbon $startTime;

    /**
     * Each subclass implements its actual work here.
     * Return a string summary for the output log.
     */
    abstract protected function handleCron(): string;

    final public function handle(): int
    {
        $this->startTime = now();
        $commandName = trim(explode(' ', $this->signature)[0]);

        if (Schema::hasTable('cron_jobs')) {
            $this->cronJob = CronJob::where('command', $commandName)->first();
        }

        if ($this->cronJob) {
            $this->cronLog = CronJobLog::create([
                'cron_job_id' => $this->cronJob->id,
                'status' => 'running',
                'started_at' => $this->startTime,
            ]);

            $this->cronJob->update([
                'last_status' => 'running',
                'last_ran_at' => $this->startTime,
            ]);
        }

        try {
            $output = $this->handleCron();
            $this->finishLog('success', $output);
            $this->info($output);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->finishLog('failed', null, $e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function finishLog(string $status, ?string $output, ?\Throwable $e = null): void
    {
        $durationMs = (int) ($this->startTime->diffInMilliseconds(now()));

        $this->cronLog?->update([
            'status' => $status,
            'output' => $output,
            'exception' => $e ? $e->getMessage()."\n".$e->getTraceAsString() : null,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);

        $this->cronJob?->update([
            'last_status' => $status,
            'next_run_at' => $this->cronJob->calculateNextRunAt(now()),
        ]);

        if ($status === 'failed' && $this->cronJob && ! config('telegram.cron_manual_run', false)) {
            app(TelegramMonitorBridge::class)->cronJobRun(
                $this->cronJob,
                'failed',
                'scheduled',
                $output,
                $e?->getMessage(),
                $durationMs,
            );
        }
    }
}
