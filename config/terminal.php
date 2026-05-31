<?php

return [

    'websocket' => [
        'enabled' => (bool) env('CONTAINER_TERMINAL_WS_ENABLED', true),

        // Bind address for `php artisan container:terminal-ws`
        'host' => env('CONTAINER_TERMINAL_WS_HOST', '0.0.0.0'),
        'port' => (int) env('CONTAINER_TERMINAL_WS_PORT', 8088),

        // Public URL the browser connects to (put behind nginx TLS proxy in production).
        'public_url' => rtrim((string) env('CONTAINER_TERMINAL_WS_PUBLIC_URL', ''), '/')
            ?: null,

        'path' => env('CONTAINER_TERMINAL_WS_PATH', '/container-terminal'),

        'max_request_size' => (int) env('CONTAINER_TERMINAL_WS_MAX_REQUEST_SIZE', 8192),

        'max_message_size' => (int) env('CONTAINER_TERMINAL_WS_MAX_MESSAGE_SIZE', 65536),
    ],

    'pty' => [
        'default_cols' => 120,
        'default_rows' => 30,
        'shell' => '/bin/sh',
    ],

];
