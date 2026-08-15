<?php

namespace App\Console\Commands;

use App\Services\Cron\SchedulerHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class VerifyCronRuntimeCommand extends Command
{
    protected $signature = 'cron:verify-runtime';

    protected $description = 'Fail unless scheduler and queue runtime prerequisites are healthy';

    public function handle(SchedulerHealthService $health): int
    {
        $errors = [];
        $status = $health->status();

        if (! $status['scheduler_enabled']) {
            $errors[] = 'SCHEDULER_ENABLED is false.';
        }

        if (! $status['heartbeat_fresh']) {
            $errors[] = 'Scheduler heartbeat is stale or missing.';
        }

        if ((int) $status['enabled_jobs'] === 0) {
            $errors[] = 'No platform cron jobs are enabled.';
        }

        if (config('queue.default') === 'sync') {
            $errors[] = 'QUEUE_CONNECTION=sync cannot process production background work.';
        }

        $requiredTables = [];
        if (config('queue.default') === 'database') {
            $requiredTables[] = 'jobs';
        }
        if (str_starts_with((string) config('queue.failed.driver'), 'database')) {
            $requiredTables[] = 'failed_jobs';
        }

        foreach (array_unique($requiredTables) as $table) {
            if (! Schema::hasTable($table)) {
                $errors[] = "Required queue table '{$table}' is missing.";
            }
        }

        if (config('scheduler.use_on_one_server')
            && ! in_array((string) config('cache.default'), ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            $errors[] = 'SCHEDULER_ON_ONE_SERVER requires a shared lock-capable CACHE_STORE.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scheduler healthy: heartbeat %s; %d enabled jobs; queue=%s; cache=%s.',
            $status['heartbeat_at'] ?? 'unknown',
            $status['enabled_jobs'],
            config('queue.default'),
            config('cache.default'),
        ));

        return self::SUCCESS;
    }
}
