<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Talksasa runtime images
    |--------------------------------------------------------------------------
    |
    | Custom images are built on container nodes when missing. Source Dockerfiles
    | live under deploy/docker/runtimes/.
    |
    */
    'runtime_registry' => env('CONTAINER_RUNTIME_REGISTRY', 'talksasa'),

    'runtime_build_path' => env('CONTAINER_RUNTIME_BUILD_PATH', '/opt/talksasa/runtime-builds'),

    'runtime_build_on_deploy' => (bool) env('CONTAINER_RUNTIME_BUILD_ON_DEPLOY', true),

    'runtime_templates' => [
        'laravel' => [
            'runtime' => 'laravel',
            'default_tag' => '8.3',
        ],
        'php' => [
            'runtime' => 'php',
            'default_tag' => '8.3',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel application initialization
    |--------------------------------------------------------------------------
    */
    'laravel_init' => [
        'composer_constraint' => env('LARAVEL_INIT_COMPOSER_CONSTRAINT', '^12.0'),
        'command_timeout_seconds' => (int) env('LARAVEL_INIT_COMMAND_TIMEOUT', 600),
        'placeholder_paths' => [
            '.keep',
            'index.html',
            'public',
            'public/index.html',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redeploy behaviour
    |--------------------------------------------------------------------------
    */
    'redeploy' => [
        // When true, the redeploy form pre-checks "Reset database" (destructive).
        'reset_database_default' => (bool) env('CONTAINER_REDEPLOY_RESET_DATABASE_DEFAULT', false),
        'migrate_max_attempts' => (int) env('CONTAINER_REDEPLOY_MIGRATE_MAX_ATTEMPTS', 6),
        'migrate_retry_delay_seconds' => (int) env('CONTAINER_REDEPLOY_MIGRATE_RETRY_DELAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Container file editor (Files tab)
    |--------------------------------------------------------------------------
    */
    'file_editor' => [
        'max_bytes' => (int) env('CONTAINER_FILE_EDITOR_MAX_BYTES', 524288),
        'view_max_bytes' => (int) env('CONTAINER_FILE_VIEW_MAX_BYTES', 2097152),
        'editable_extensions' => [
            'php', 'env', 'example', 'blade', 'js', 'css', 'scss', 'json', 'xml', 'yml', 'yaml',
            'md', 'txt', 'html', 'htm', 'sql', 'sh', 'ini', 'conf', 'log', 'vue', 'ts', 'tsx',
            'artisan', 'keep',
        ],
    ],

];
