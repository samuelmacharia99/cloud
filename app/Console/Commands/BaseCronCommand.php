<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronJobLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

    public final function handle(): int
    {
        $this->startTime = now();
        $this->cronJob = CronJob::where('command', $this->getName())->first();

        // Start log entry
        $this->cronLog = CronJobLog::create([
            'cron_job_id' => $this->cronJob?->id,
            'status' => 'running',
            'started_at' => $this->startTime,
        ]);

        // Mark parent job as running
        $this->cronJob?->update([
            'last_status' => 'running',
            'last_ran_at' => $this->startTime,
        ]);

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
            'exception' => $e ? $e->getMessage() . "\n" . $e->getTraceAsString() : null,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
        ]);

        $this->cronJob?->update([
            'last_status' => $status,
            'next_run_at' => $this->cronJob->calculateNextRunAt(),
        ]);
    }
}
