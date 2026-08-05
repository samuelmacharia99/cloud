<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ContainerApiController;
use App\Http\Controllers\Api\V1\ContainerTemplateApiController;
use App\Http\Controllers\Api\V1\NodeApiController;

$healthCheck = function () {
    $database = 'connected';
    $cache = 'working';
    $ok = true;

    try {
        DB::connection()->getPdo();
    } catch (\Throwable) {
        $database = 'error';
        $ok = false;
    }

    try {
        $key = 'healthcheck:'.uniqid('', true);
        Cache::put($key, '1', 10);
        if (Cache::get($key) !== '1') {
            throw new \RuntimeException('cache read mismatch');
        }
        Cache::forget($key);
    } catch (\Throwable) {
        $cache = 'error';
        $ok = false;
    }

    $payload = [
        'status' => $ok ? 'ok' : 'degraded',
        'database' => $database,
        'cache' => $cache,
    ];

    return response()->json($payload, $ok ? 200 : 503);
};

// Public endpoint for health check
Route::get('/health', $healthCheck);

// Protected endpoints
Route::middleware('auth:sanctum')->group(function () {
    // User info
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // API v1 endpoints
    Route::prefix('v1')->group(function () {
        // Container operations
        Route::prefix('services/{service}/container')->group(function () {
            Route::get('', [ContainerApiController::class, 'show'])->name('api.container.show');
            Route::post('start', [ContainerApiController::class, 'start'])->name('api.container.start');
            Route::post('stop', [ContainerApiController::class, 'stop'])->name('api.container.stop');
            Route::post('restart', [ContainerApiController::class, 'restart'])->name('api.container.restart');
            Route::get('logs', [ContainerApiController::class, 'logs'])->name('api.container.logs');
            Route::get('metrics', [ContainerApiController::class, 'metrics'])->name('api.container.metrics');

            // Backup operations
            Route::post('backups', [ContainerApiController::class, 'createBackup'])->name('api.container.backups.create');
            Route::get('backups', [ContainerApiController::class, 'listBackups'])->name('api.container.backups.list');
            Route::post('backups/{backup}/restore', [ContainerApiController::class, 'restoreBackup'])->name('api.container.backups.restore');
            Route::delete('backups/{backup}', [ContainerApiController::class, 'deleteBackup'])->name('api.container.backups.delete');
        });

        // Container templates (admin only — may expose internal stack metadata)
        Route::middleware('admin')->group(function () {
            Route::get('container-templates', [ContainerTemplateApiController::class, 'index'])->name('api.templates.index');
            Route::get('container-templates/{template}', [ContainerTemplateApiController::class, 'show'])->name('api.templates.show');
        });

        // Admin-only node operations
        Route::middleware('admin')->prefix('nodes')->group(function () {
            Route::get('', [NodeApiController::class, 'index'])->name('api.nodes.index');
            Route::get('{node}', [NodeApiController::class, 'show'])->name('api.nodes.show');
        });
    });
});

// Admin-only health endpoints
Route::middleware(['auth:sanctum', 'admin'])->group(function () use ($healthCheck) {
    Route::get('/admin/health', $healthCheck);
});
