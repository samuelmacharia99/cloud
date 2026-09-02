<?php

namespace App\Console\Commands;

use App\Services\Provisioning\ContainerCronService;

class RunContainerCronJobsCommand extends BaseCronCommand
{
    /**
     * One customer job failing to queue is not a platform outage. Page only
     * when an entire batch cannot dispatch (queue/DB typically).
     */
    public const PLATFORM_FAILURE_MIN_DISPATCH_FAILURES = 3;

    protected $signature = 'cron:run-container-jobs';

    protected $description = 'Dispatch due customer container cron jobs to supervised workers';

    protected function handleCron(): string
    {
        $summary = app(ContainerCronService::class)->runDueJobs();

        $message = sprintf(
            'Processed %d container cron job(s): %d dispatched, %d dispatch failures, %d skipped.',
            $summary['processed'],
            $summary['dispatched'],
            $summary['failed'],
            $summary['skipped'],
        );

        if (($summary['deferred'] ?? 0) > 0) {
            $message .= sprintf(' Deferred %d job(s) to the next minute (batch time budget).', $summary['deferred']);
        }

        if ($summary['failed'] > 0) {
            \Log::warning('Container cron dispatcher recorded customer job failures', $summary);
        }

        if (
            $summary['failed'] >= self::PLATFORM_FAILURE_MIN_DISPATCH_FAILURES
            && $summary['dispatched'] === 0
        ) {
            throw new \RuntimeException($message);
        }

        return $message;
    }
}
