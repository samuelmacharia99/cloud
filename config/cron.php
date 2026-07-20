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
        // Live tar of WordPress volumes + optional Hetzner upload; one site can
        // take up to ContainerBackupService::BACKUP_TIMEOUT (3600s).
        'cron:backup-containers' => 14400, // 4 hours
        'cron:collect-reseller-disk-usage' => 1800,
        'cron:reconcile-directadmin-hosted-accounts' => 1800,
    ],

];
