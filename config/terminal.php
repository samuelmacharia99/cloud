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
        // Prefer bash when present inside the container; SSH bridge falls back to /bin/sh.
        'shell' => env('CONTAINER_TERMINAL_SHELL', '/bin/bash'),
        'shell_fallback' => '/bin/sh',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session lifetime
    |--------------------------------------------------------------------------
    */
    'session' => [
        // Idle timeout: refreshed on each command / PTY activity / client ping / extend.
        'idle_minutes' => (int) env('CONTAINER_TERMINAL_IDLE_MINUTES', 240),
        // Absolute cap regardless of activity.
        'hard_hours' => (int) env('CONTAINER_TERMINAL_HARD_HOURS', 12),
        // Concurrent tabs per user+service (oldest closed when exceeded).
        'max_per_user_service' => (int) env('CONTAINER_TERMINAL_MAX_SESSIONS', 3),
        // Minutes added when the user clicks "Extend session".
        'extend_minutes' => (int) env('CONTAINER_TERMINAL_EXTEND_MINUTES', 240),
    ],

    'security' => [
        'max_command_length' => (int) env('CONTAINER_TERMINAL_MAX_COMMAND_LENGTH', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP fallback command timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | Used when the interactive WebSocket terminal is unavailable. Long-running
    | artisan, composer, and build commands need higher limits than shell tools.
    |
    */
    'command_timeouts' => [
        'default' => (int) env('CONTAINER_TERMINAL_COMMAND_TIMEOUT', 120),
        'artisan' => (int) env('CONTAINER_TERMINAL_ARTISAN_TIMEOUT', 600),
        'artisan_long' => (int) env('CONTAINER_TERMINAL_ARTISAN_LONG_TIMEOUT', 900),
        'build' => (int) env('CONTAINER_TERMINAL_BUILD_TIMEOUT', 900),
        'network' => (int) env('CONTAINER_TERMINAL_NETWORK_TIMEOUT', 180),
    ],

];
