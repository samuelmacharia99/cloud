<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cache-backed state for the file manager's long operations (archive
 * extraction, zip builds). The page polls by token; tokens are bound to the
 * service that started them so one customer can never read another's.
 */
class ContainerFileOperationProgress
{
    public const TYPE_EXTRACT = 'extract';

    public const TYPE_ARCHIVE = 'archive';

    public const STATUSES_ACTIVE = ['queued', 'running'];

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function start(Service $service, ContainerDeployment $deployment, string $type, array $meta = []): array
    {
        $state = [
            'token' => (string) Str::uuid(),
            'service_id' => (int) $service->id,
            'deployment_id' => (int) $deployment->id,
            'type' => $type,
            'status' => 'queued',
            'percent' => 1,
            'label' => 'Queued. Waiting for a worker…',
            'error' => null,
            'result' => null,
            'meta' => $meta,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        $this->persist($state);

        return $state;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $token, ?Service $service = null): ?array
    {
        $state = Cache::get($this->key($token));
        if (! is_array($state)) {
            return null;
        }
        if ($service && (int) ($state['service_id'] ?? 0) !== (int) $service->id) {
            return null;
        }

        return $state;
    }

    public function update(string $token, int $percent, string $label): void
    {
        $this->mutate($token, [
            'status' => 'running',
            'percent' => max(1, min(99, $percent)),
            'label' => $label,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function complete(string $token, string $label, array $result = []): void
    {
        $this->mutate($token, [
            'status' => 'completed',
            'percent' => 100,
            'label' => $label,
            'result' => $result,
            'error' => null,
        ]);
    }

    /**
     * A built archive was handed to the browser; the remote copy is gone.
     */
    public function markDownloaded(string $token): void
    {
        $this->mutate($token, [
            'status' => 'downloaded',
            'label' => 'Downloaded.',
            'result' => null,
        ]);
    }

    public function fail(string $token, string $error): void
    {
        $this->mutate($token, [
            'status' => 'failed',
            'label' => $error,
            'error' => $error,
        ]);
    }

    public function isActive(array $state): bool
    {
        return in_array((string) ($state['status'] ?? ''), self::STATUSES_ACTIVE, true);
    }

    public function ttlSeconds(): int
    {
        return max(60, (int) config('containers.file_manager.operation_ttl_minutes', 60) * 60);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function mutate(string $token, array $changes): void
    {
        $state = $this->find($token);
        if ($state === null) {
            return;
        }
        $state = array_merge($state, $changes, ['updated_at' => now()->toIso8601String()]);
        $this->persist($state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persist(array $state): void
    {
        Cache::put($this->key((string) $state['token']), $state, $this->ttlSeconds());
    }

    private function key(string $token): string
    {
        return 'container_fm_op.'.$token;
    }
}
