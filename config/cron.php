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
        // Runs every five minutes and degrades individual node/container failures
        // instead of failing the scheduler. The command stops before this threshold.
        'cron:collect-container-metrics' => 240,
        'cron:refresh-project-consumption' => 180,
        // Runs every minute; each customer job is capped, but a full batch can
        // still take a few minutes when SSH is slow.
        'cron:run-container-jobs' => 600,
        // Probes DirectAdmin + Docker for up to 100 services; a slow node is
        // expected to take several minutes. The command stops at live_status.max_runtime_seconds.
        'cron:sync-service-live-status' => 900,
        // Six SSH commands per node every two minutes.
        'cron:poll-node-health' => 300,
        // Compose restarts wait up to 120s each.
        'cron:auto-restart-containers' => 600,
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
        'cron:collect-container-metrics' => 5,
        'cron:refresh-project-consumption' => 10,
        'cron:run-container-jobs' => 15,
        'cron:sync-service-live-status' => 20,
        'cron:poll-node-health' => 5,
        'cron:auto-restart-containers' => 15,
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

    'container_metrics' => [
        // Leave headroom for finalizing logs before the platform queue timeout.
        'max_runtime_seconds' => 210,
        'stats_timeout_seconds' => 12,
        'disk_timeout_seconds' => 12,
        // Full-tree `du` is expensive; reuse the last disk sample between intervals.
        'disk_interval_minutes' => 55,
        'warning_cooldown_minutes' => 30,
    ],

    'live_status' => [
        // Stop starting new probes so the 15-minute cadence can continue
        // oldest-first instead of hanging the worker on a 100-service batch.
        'max_runtime_seconds' => 240,
    ],

];
