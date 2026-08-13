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
        // Live tar + optional Hetzner upload; one site can take up to
        // ContainerBackupService::BACKUP_TIMEOUT (3600s) plus upload time.
        // The command itself stops starting new backups before this wall clock.
        'cron:backup-containers' => 14400, // 4 hours
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
        'cron:backup-containers' => 300, // 5h — beyond hang threshold
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
        // Stop starting new backups after this wall-clock so the job finishes
        // cleanly before cron:check-health treats it as hung.
        'max_runtime_seconds' => 12600, // 3.5 hours
    ],

];
