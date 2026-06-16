<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduler master switch
    |--------------------------------------------------------------------------
    |
    | When false, schedule:run exits immediately with no registered tasks.
    | Defaults to off in local development to avoid background CPU/disk load.
    |
    */
    'enabled' => env('SCHEDULER_ENABLED', env('APP_ENV') !== 'local'),

    /*
    |--------------------------------------------------------------------------
    | Distributed lock (onOneServer)
    |--------------------------------------------------------------------------
    |
    | Requires a shared cache store (redis/database). Disabled locally.
    |
    */
    'use_on_one_server' => env('SCHEDULER_ON_ONE_SERVER', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Crontab output logging
    |--------------------------------------------------------------------------
    |
    | When true, schedule:run stdout/stderr is appended to storage/logs/cron.log.
    | Leave false in production unless debugging — idle runs spam the log file.
    |
    */
    'log_schedule_output' => env('SCHEDULER_LOG_OUTPUT', false),

    /*
    |--------------------------------------------------------------------------
    | Heavy jobs skipped in local environment
    |--------------------------------------------------------------------------
    |
    | These commands touch SSH, Docker, or external APIs and are not needed
    | while developing on a laptop.
    |
    */
    'skip_in_local' => [
        'cron:collect-container-metrics',
        'cron:poll-node-health',
        'cron:check-node-health',
        'cron:auto-restart-containers',
        'cron:backup-containers',
        'cron:provision-pending-containers',
        'directadmin:provision-pending',
        'cron:provision-reseller-ssl',
    ],

    /*
    |--------------------------------------------------------------------------
    | cron.log rotation
    |--------------------------------------------------------------------------
    */
    'cron_log_max_bytes' => (int) env('SCHEDULER_CRON_LOG_MAX_BYTES', 5 * 1024 * 1024),

];
