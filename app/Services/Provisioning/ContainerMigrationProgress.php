<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;

/**
 * Live operator view of a container migration (percent, phase, streamed log).
 *
 * The worker appends to the cache because a migration emits hundreds of lines,
 * and the state is mirrored into service_meta so the console still renders after
 * a cache flush, a page reload, or a worker crash.
 */
class ContainerMigrationProgress
{
    public const STATUSES_ACTIVE = ['queued', 'running'];

    private const CACHE_TTL_SECONDS = 21600;

    private const LOG_MAX_BYTES = 120000;

    private const META_LOG_MAX_BYTES = 24000;

    /**
     * Ordered milestones and the fraction of the whole move each one spans.
     * Snapshot and transfer dominate because they carry the customer's bytes.
     *
     * @var array<string, array{label: string, start: float, end: float}>
     */
    public const PHASES = [
        'queued' => ['label' => 'Queued', 'start' => 0.00, 'end' => 0.01],
        'preflight' => ['label' => 'Check the target host', 'start' => 0.01, 'end' => 0.06],
        'snapshot' => ['label' => 'Stop the app and snapshot files and volumes', 'start' => 0.06, 'end' => 0.34],
        'transfer' => ['label' => 'Copy the verified bundle to the new host', 'start' => 0.34, 'end' => 0.66],
        'restore' => ['label' => 'Restore files and volumes on the new host', 'start' => 0.66, 'end' => 0.80],
        'start' => ['label' => 'Start the stack on the new host', 'start' => 0.80, 'end' => 0.87],
        'verify' => ['label' => 'Wait for every container to report healthy', 'start' => 0.87, 'end' => 0.92],
        'cutover' => ['label' => 'Point the platform at the new host', 'start' => 0.92, 'end' => 0.94],
        'domains' => ['label' => 'Rebind domains and verify they answer', 'start' => 0.94, 'end' => 0.98],
        'cleanup' => ['label' => 'Release the old host', 'start' => 0.98, 'end' => 1.00],
        'done' => ['label' => 'Migration complete', 'start' => 1.00, 'end' => 1.00],
    ];

    /** @var array<int, float> */
    private array $lastMetaWriteAt = [];

    public function cacheKey(Service|int $service): string
    {
        $id = $service instanceof Service ? $service->id : $service;

        return 'container_migration.'.$id;
    }

    public function isActive(Service $service): bool
    {
        return in_array($this->snapshot($service)['status'] ?? '', self::STATUSES_ACTIVE, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(Service $service, Node $source, Node $target, string $reason): array
    {
        $state = $this->blank($service);
        $state['status'] = 'queued';
        $state['phase'] = 'queued';
        $state['label'] = 'Queued move to '.$target->hostname;
        $state['reason'] = $reason;
        $state['source_node_id'] = $source->id;
        $state['source_hostname'] = (string) $source->hostname;
        $state['target_node_id'] = $target->id;
        $state['target_hostname'] = (string) $target->hostname;
        $state['started_at'] = now()->toIso8601String();
        $state['completed_at'] = null;
        $state['error'] = null;
        $state['rolled_back'] = false;
        $this->appendLog($state, 'Queued migration of '.$service->name.' from '.$source->hostname.' to '.$target->hostname.' ('.$reason.')');
        $this->appendLog($state, 'Waiting for a queue worker to pick the job up…');
        $this->persist($service, $state, true);

        return $state;
    }

    /**
     * Open a run that did not come from the admin form.
     *
     * Automatic evacuations off a pressured host call the migration service
     * directly, so without this the console would stream a move with no idea
     * which hosts were involved.
     */
    public function begin(Service $service, Node $source, Node $target, string $reason): void
    {
        $state = $this->snapshot($service);
        if (! in_array($state['status'], self::STATUSES_ACTIVE, true)) {
            $state = $this->blank($service);
            $state['started_at'] = now()->toIso8601String();
        }

        $state['status'] = 'running';
        $state['reason'] = $reason;
        $state['source_node_id'] = $source->id;
        $state['source_hostname'] = (string) $source->hostname;
        $state['target_node_id'] = $target->id;
        $state['target_hostname'] = (string) $target->hostname;
        $state['completed_at'] = null;
        $state['error'] = null;
        $state['failed_phase'] = '';
        $state['rolled_back'] = false;
        $this->appendLog($state, 'Worker started: moving '.$service->name.' from '.$source->hostname.' to '.$target->hostname.' ('.$reason.')');
        $this->persist($service, $state, true);
    }

    /**
     * Move to a milestone. Pass bytes to animate long transfers inside one phase.
     */
    public function phase(Service $service, string $phase, string $label = '', int $bytesDone = 0, int $bytesTotal = 0): void
    {
        $state = $this->snapshot($service);
        $state['status'] = 'running';
        $state['phase'] = $phase;
        $state['label'] = $label !== '' ? $label : (self::PHASES[$phase]['label'] ?? $phase);
        $state['bytes_done'] = max(0, $bytesDone);
        if ($bytesTotal > 0) {
            $state['bytes_total'] = $bytesTotal;
        }
        $state['phase_fraction'] = $bytesTotal > 0 ? min(1, max(0, $bytesDone / $bytesTotal)) : 0.0;
        $this->appendLog($state, $state['label']);
        $this->persist($service, $state, true);
    }

    public function log(Service $service, string $line): void
    {
        $state = $this->snapshot($service);
        $this->appendLog($state, $line);
        $this->persist($service, $state, false);
    }

    /**
     * Keep the console alive during a long single command so operators can tell
     * the difference between "still copying" and "worker died".
     */
    public function heartbeat(Service $service): void
    {
        $state = $this->snapshot($service);
        $this->persist($service, $state, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(Service $service, string $message): array
    {
        $state = $this->snapshot($service);
        $state['status'] = 'completed';
        $state['phase'] = 'done';
        $state['phase_fraction'] = 1.0;
        $state['label'] = $message;
        $state['error'] = null;
        $state['completed_at'] = now()->toIso8601String();
        $this->appendLog($state, $message);
        $this->persist($service, $state, true);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function fail(Service $service, string $message, bool $rolledBack = false): array
    {
        $state = $this->snapshot($service);
        $state['status'] = 'failed';
        $state['label'] = $message;
        $state['error'] = $message;
        $state['rolled_back'] = $rolledBack;
        $state['failed_phase'] = (string) ($state['phase'] ?? '');
        $state['completed_at'] = now()->toIso8601String();
        $this->appendLog($state, 'FAILED: '.$message);
        if ($rolledBack) {
            $this->appendLog($state, 'The app was left running on '.($state['source_hostname'] ?: 'the original host').'. No data was released.');
        }
        $this->persist($service, $state, true);

        return $state;
    }

    /**
     * Close out a run whose worker died without reporting (timeout, OOM, kill).
     */
    public function failIfActive(Service $service, string $message): void
    {
        if ($this->isActive($service)) {
            $this->fail($service, $message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Service $service): array
    {
        try {
            $cached = Cache::get($this->cacheKey($service));
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached) && ($cached['status'] ?? '') !== '') {
            return $this->normalize($cached);
        }

        $meta = is_array($service->service_meta['container_migration'] ?? null)
            ? $service->service_meta['container_migration']
            : [];

        return $this->normalize($meta === [] ? $this->blank($service) : $meta);
    }

    /**
     * Payload consumed by the admin migration console.
     *
     * @return array<string, mixed>
     */
    public function operatorView(Service $service): array
    {
        $state = $this->snapshot($service);
        $active = in_array($state['status'], self::STATUSES_ACTIVE, true);

        return array_merge($state, [
            'is_active' => $active,
            'is_failed' => $state['status'] === 'failed',
            'is_done' => $state['status'] === 'completed',
            'can_start' => ! $active,
            'percent' => $this->computePercent($state),
            'steps' => $this->steps($state),
            'log' => $state['log'] !== '' ? $state['log'] : 'No migration has run for this service yet.',
            'label' => $state['label'] !== '' ? $state['label'] : 'Idle',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{key: string, label: string, status: string}>
     */
    public function steps(array $state): array
    {
        $current = (string) ($state['phase'] ?? 'queued');
        $status = (string) ($state['status'] ?? 'idle');
        $keys = array_keys(self::PHASES);
        $currentIndex = array_search($current, $keys, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;

        $out = [];
        foreach ($keys as $index => $key) {
            if ($key === 'queued' || $key === 'done') {
                continue;
            }

            if ($status === 'completed') {
                $stepStatus = 'completed';
            } elseif ($index < $currentIndex) {
                $stepStatus = 'completed';
            } elseif ($index === $currentIndex) {
                $stepStatus = $status === 'failed' ? 'failed' : 'running';
            } else {
                $stepStatus = 'pending';
            }

            $out[] = [
                'key' => $key,
                'label' => self::PHASES[$key]['label'],
                'status' => $stepStatus,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function computePercent(array $state): int
    {
        $status = (string) ($state['status'] ?? 'idle');
        if ($status === 'completed') {
            return 100;
        }

        if ($status === 'idle') {
            return 0;
        }

        $phase = self::PHASES[(string) ($state['phase'] ?? 'queued')] ?? self::PHASES['queued'];
        $fraction = min(1, max(0, (float) ($state['phase_fraction'] ?? 0)));
        $percent = (int) round(($phase['start'] + (($phase['end'] - $phase['start']) * $fraction)) * 100);

        if ($status === 'failed') {
            return max(2, min(99, $percent));
        }

        return max(1, min(99, $percent));
    }

    public static function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($value >= 10 ? number_format($value, 0) : number_format($value, 1)).' '.$units[$unit];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(Service $service): array
    {
        return $this->normalize([
            'service_id' => $service->id,
            'status' => 'idle',
            'phase' => 'queued',
            'label' => '',
            'log' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalize(array $state): array
    {
        return [
            'service_id' => (int) ($state['service_id'] ?? 0),
            'status' => (string) ($state['status'] ?? 'idle'),
            'phase' => (string) ($state['phase'] ?? 'queued'),
            'phase_fraction' => (float) ($state['phase_fraction'] ?? 0),
            'label' => (string) ($state['label'] ?? ''),
            'reason' => (string) ($state['reason'] ?? ''),
            'source_node_id' => (int) ($state['source_node_id'] ?? 0),
            'source_hostname' => (string) ($state['source_hostname'] ?? ''),
            'target_node_id' => (int) ($state['target_node_id'] ?? 0),
            'target_hostname' => (string) ($state['target_hostname'] ?? ''),
            'bytes_done' => (int) ($state['bytes_done'] ?? 0),
            'bytes_total' => (int) ($state['bytes_total'] ?? 0),
            'log' => (string) ($state['log'] ?? ''),
            'error' => $state['error'] ?? null,
            'failed_phase' => (string) ($state['failed_phase'] ?? ''),
            'rolled_back' => (bool) ($state['rolled_back'] ?? false),
            'started_at' => $state['started_at'] ?? null,
            'completed_at' => $state['completed_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function appendLog(array &$state, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $entry = '['.now()->format('H:i:s').'] '.$line;
        $existing = (string) ($state['log'] ?? '');
        $combined = $existing === '' ? $entry : $existing."\n".$entry;
        if (strlen($combined) > self::LOG_MAX_BYTES) {
            $combined = substr($combined, -self::LOG_MAX_BYTES);
        }

        $state['log'] = $combined;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persist(Service $service, array $state, bool $force): void
    {
        $state['service_id'] = $service->id;
        $state['updated_at'] = now()->toIso8601String();

        // Reporting must never abort a migration that is already moving customer data.
        try {
            Cache::put($this->cacheKey($service), $state, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $e) {
            \Log::warning('Failed to cache container migration progress', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }

        $now = microtime(true);
        $last = $this->lastMetaWriteAt[$service->id] ?? 0.0;
        if (! $force && ($now - $last) < 1.0) {
            return;
        }

        $this->lastMetaWriteAt[$service->id] = $now;
        $metaState = $state;
        if (strlen((string) ($metaState['log'] ?? '')) > self::META_LOG_MAX_BYTES) {
            $metaState['log'] = substr((string) $metaState['log'], -self::META_LOG_MAX_BYTES);
        }

        try {
            $service->refresh();
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['container_migration'] = $metaState;
            $service->update(['service_meta' => $meta]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to mirror container migration progress', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
