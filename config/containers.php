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

    // Bumped when runtime Dockerfiles change so nodes rebuild images (e.g. new PHP extensions).
    'runtime_build_revision' => (int) env('CONTAINER_RUNTIME_BUILD_REVISION', 2),

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
        // Relative segments under /app checked for artisan (ViserLab-style apps use "core").
        'project_root_candidates' => ['', 'core', 'backend'],
        'composer_no_dev' => (bool) env('LARAVEL_COMPOSER_NO_DEV', true),
        'require_http_health' => (bool) env('LARAVEL_REQUIRE_HTTP_HEALTH', true),
        'http_health_timeout_seconds' => (int) env('LARAVEL_HTTP_HEALTH_TIMEOUT', 90),
        'welcome_template' => resource_path('container-templates/laravel/welcome.blade.php'),
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

    /*
    |--------------------------------------------------------------------------
    | Application runtime autostart (Node.js, Python, Ruby)
    |--------------------------------------------------------------------------
    |
    | Start commands are detected from Procfile, package.json, Gemfile,
    | manage.py, and other common entrypoints after source sync on deploy.
    |
    */
    'application_runtime' => [
        'slugs' => ['nodejs', 'python', 'ruby'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Node.js production build (post-pull / bootstrap)
    |--------------------------------------------------------------------------
    */
    'node_build' => [
        'command_timeout_seconds' => (int) env('NODE_BUILD_COMMAND_TIMEOUT', 900),
        'heap_limit_ratio' => (float) env('NODE_BUILD_HEAP_LIMIT_RATIO', 0.65),
        'min_heap_limit_mb' => (int) env('NODE_BUILD_MIN_HEAP_LIMIT_MB', 384),
        'unlimited_heap_limit_mb' => (int) env('NODE_BUILD_UNLIMITED_HEAP_LIMIT_MB', 4096),
    ],

];
