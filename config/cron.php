<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-command hang thresholds (seconds)
    |--------------------------------------------------------------------------
    |
    | cron:check-health uses settings.max_execution_time as the default hung
    | threshold. Long jobs below override that so legitimate work is not
    | reported as hung (and later force-failed) while still running.
    |
    */
    'hang_thresholds' => [
        // The scheduler command only queues backup jobs; workers perform I/O.
        'cron:backup-containers' => 600,
        'cron:collect-reseller-disk-usage' => 1800,
        'cron:reconcile-directadmin-hosted-accounts' => 1800,
        // Runs every minute; each customer job is capped, but a full batch can
        // still take a few minutes when SSH is slow.
        'cron:run-container-jobs' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler withoutOverlapping expires (minutes)
    |--------------------------------------------------------------------------
    |
    | Laravel releases the overlap mutex after this many minutes. Short values
    | let a second run start while a long job (e.g. container backups) is still
    | working — compounding load and leaving "running" cron logs forever.
    |
    */
    'overlap_expires_minutes' => [
        'default' => 60,
        'cron:backup-containers' => 15,
        'cron:collect-reseller-disk-usage' => 45,
        'cron:reconcile-directadmin-hosted-accounts' => 45,
        'cron:run-container-jobs' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | cron:backup-containers
    |--------------------------------------------------------------------------
    */
    'backup_containers' => [
        // Stop queueing new backups after this wall-clock budget.
        'max_runtime_seconds' => 300,
    ],

];
