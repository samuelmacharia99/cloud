<?php

namespace App\Console\Commands;

use App\Services\Provisioning\ContainerCronService;

class RunContainerCronJobsCommand extends BaseCronCommand
{
    protected $signature = 'cron:run-container-jobs';

    protected $description = 'Execute due customer container cron jobs via docker exec';

    protected function handleCron(): string
    {
        $summary = app(ContainerCronService::class)->runDueJobs();

        return sprintf(
            'Processed %d container cron job(s): %d succeeded, %d failed, %d skipped.',
            $summary['processed'],
            $summary['succeeded'],
            $summary['failed'],
            $summary['skipped'],
        );
    }
}
