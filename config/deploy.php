<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production-safe seeders
    |--------------------------------------------------------------------------
    |
    | Only these seeder classes may run via `db:seed --class=...` when
    | APP_ENV=production. They must be idempotent and must not overwrite
    | customer billing, settings, or transactional data.
    |
    */
    'production_allowed_seeders' => [
        'CronJobSeeder',
        'Database\\Seeders\\CronJobSeeder',
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Artisan commands (production)
    |--------------------------------------------------------------------------
    */
    'production_blocked_commands' => [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
        'schema:dump',
        'db:seed',
    ],

];
