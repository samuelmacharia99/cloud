<?php

namespace App\Jobs\Concerns;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

/**
 * WithoutOverlapping releases its lock in a finally block, which a fatal
 * error (memory limit, timeout) never reaches. The lock then blocks every
 * retry on the same key until it expires. Jobs that run long remote work
 * register this so the lock goes with the process.
 */
trait ReleasesOverlapLockOnFatal
{
    /**
     * The cache key WithoutOverlapping uses for this job and key.
     */
    public static function overlapLockKey(string $key): string
    {
        return (new WithoutOverlapping($key))->prefix.static::class.':'.$key;
    }

    protected function releaseOverlapLockOnFatal(string $key): void
    {
        $lockKey = static::overlapLockKey($key);
        $reserve = str_repeat(' ', 64 * 1024);
        register_shutdown_function(static function () use ($lockKey, &$reserve): void {
            $reserve = null;
            $error = error_get_last();
            if (! is_array($error) || ! in_array((int) ($error['type'] ?? 0), [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                return;
            }
            try {
                @ini_set('memory_limit', '-1');
                Cache::lock($lockKey)->forceRelease();
            } catch (\Throwable) {
                // Nothing left to do in a dying process.
            }
        });
    }
}
