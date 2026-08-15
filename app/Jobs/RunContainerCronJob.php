<?php

namespace App\Jobs;

use App\Models\ContainerCronJob;
use App\Models\ContainerCronJobRun;
use App\Services\Provisioning\ContainerCronService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunContainerCronJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $cronJobId,
        public int $runId,
    ) {
        $this->timeout = max(30, (int) config('containers.cron.command_timeout_seconds', 60) + 30);
        $this->onQueue('container-cron');
    }

    public function handle(ContainerCronService $cron): void
    {
        $job = ContainerCronJob::query()->find($this->cronJobId);
        $run = ContainerCronJobRun::query()->find($this->runId);

        if (! $job || ! $run || $run->status !== 'queued') {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $cron->execute($job, alreadyClaimed: true, run: $run);
        } catch (\Throwable $e) {
            $cron->recordRunFailure($job, $run, $e);

            throw $e;
        }
    }

    public function failed(?\Throwable $e): void
    {
        $job = ContainerCronJob::query()->find($this->cronJobId);
        $run = ContainerCronJobRun::query()->find($this->runId);

        if (! $job || ! $run || $run->status === 'failed') {
            return;
        }

        app(ContainerCronService::class)->recordRunFailure(
            $job,
            $run,
            $e ?? new \RuntimeException('Container cron queue job failed.')
        );
    }
}
