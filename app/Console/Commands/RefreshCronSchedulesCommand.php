<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use Illuminate\Console\Command;

class RefreshCronSchedulesCommand extends Command
{
    protected $signature = 'cron:refresh-schedules';

    protected $description = 'Recalculate next_run_at for all cron jobs from their cron expressions';

    public function handle(): int
    {
        $count = 0;

        foreach (CronJob::query()->cursor() as $job) {
            $job->refreshNextRunAt();
            $count++;
        }

        $this->info("Refreshed next run time for {$count} cron job(s).");

        return self::SUCCESS;
    }
}
