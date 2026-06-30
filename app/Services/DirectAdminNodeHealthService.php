<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DirectAdminNodeHealthService
{
    private const FAILURE_CACHE_PREFIX = 'da_node_api_failures:';

    private const DEGRADED_THRESHOLD = 3;

    private const OFFLINE_THRESHOLD = 8;

    public function recordSuccess(?Node $node): void
    {
        if (! $node || $node->type !== 'directadmin') {
            return;
        }

        Cache::forget(self::FAILURE_CACHE_PREFIX.$node->id);

        if (in_array($node->status, ['offline', 'degraded'], true)) {
            $node->update([
                'status' => 'online',
                'last_health_check_at' => now(),
            ]);
        }
    }

    public function recordFailure(?Node $node, string $reason): void
    {
        if (! $node || $node->type !== 'directadmin') {
            return;
        }

        $key = self::FAILURE_CACHE_PREFIX.$node->id;
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, 3600);

        $targetStatus = $node->status;
        if ($failures >= self::OFFLINE_THRESHOLD) {
            $targetStatus = 'offline';
        } elseif ($failures >= self::DEGRADED_THRESHOLD) {
            $targetStatus = 'degraded';
        }

        if ($targetStatus !== $node->status) {
            $node->update([
                'status' => $targetStatus,
                'last_health_check_at' => now(),
            ]);
        }

        Log::warning('DirectAdmin node API failure recorded', [
            'node_id' => $node->id,
            'node_name' => $node->name,
            'failures' => $failures,
            'status' => $targetStatus,
            'reason' => $reason,
        ]);
    }

    public function failureCount(Node $node): int
    {
        return (int) Cache::get(self::FAILURE_CACHE_PREFIX.$node->id, 0);
    }
}
