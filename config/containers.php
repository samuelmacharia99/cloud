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

];
