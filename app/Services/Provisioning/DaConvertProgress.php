<?php

namespace App\Services\Provisioning;

use App\Jobs\ConvertDirectAdminProjectSiteJob;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\ContainerDeploymentEvent;
use App\Models\CustomerProject;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Live operator view of a DirectAdmin → Application Hosting convert.
 *
 * Owns everything under service_meta.da_convert that describes progress: the
 * ordered step log, the current phase and its fraction, heartbeat, and the
 * percent derived from a weighted phase table. The primary convert and each
 * sibling site convert both record here; the primary's view aggregates its
 * siblings so the operator sees the whole account from one page.
 */
class DaConvertProgress
{
    public const MODE_PRIMARY = 'convert_in_place';

    public const MODE_SITE = 'project_site';

    public const STATUSES_ACTIVE = ['queued', 'running'];

    /** @var array<string, array{0: int, 1: int}> */
    public const PRIMARY_PHASES = [
        'queued' => [0, 1],
        'preflight' => [1, 4],
        'capacity' => [4, 6],
        'export' => [6, 22],
        'switch' => [22, 24],
        'deploy' => [24, 46],
        'import' => [46, 70],
        'bind' => [70, 78],
        'mail' => [78, 92],
        'siblings' => [92, 98],
        'complete' => [100, 100],
    ];

    /** @var array<string, array{0: int, 1: int}> */
    public const SITE_PHASES = [
        'queued' => [0, 1],
        'preflight' => [1, 4],
        'capacity' => [4, 8],
        'export' => [8, 40],
        'deploy' => [40, 68],
        'import' => [68, 94],
        'bind' => [94, 98],
        'complete' => [100, 100],
    ];

    private const STEPS_MAX = 400;

    /** @var array<int, float> */
    private array $lastWriteAt = [];

    /**
     * @return array<string, mixed>
     */
    public function convertMeta(Service $service): array
    {
        $convert = $service->service_meta['da_convert'] ?? null;

        return is_array($convert) ? $convert : [];
    }

    /**
     * Begin (or restart) a convert on this row. Steps and error are cleared;
     * everything else in the seed is merged over the existing meta so
     * previous/options survive a retry.
     *
     * @param  array<string, mixed>  $seed
     */
    public function start(Service $service, array $seed = []): void
    {
        $this->merge($service, array_merge([
            'status' => 'running',
            'mode' => self::MODE_PRIMARY,
            'started_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
            'completed_at' => null,
            'failed_at' => null,
            'error' => null,
            'steps' => [],
            'phase' => 'preflight',
            'phase_fraction' => 0.0,
            'phase_detail' => '',
            'percent' => 0,
        ], $seed));
    }

    /**
     * Append a line to the step log, optionally moving to a new phase.
     */
    public function step(Service $service, string $line, ?string $phase = null, float $fraction = 0.0): void
    {
        $service->refresh();
        $convert = $this->convertMeta($service);
        $steps = is_array($convert['steps'] ?? null) ? $convert['steps'] : [];
        $steps[] = $line;
        if (count($steps) > self::STEPS_MAX) {
            $steps = array_slice($steps, -self::STEPS_MAX);
        }

        $data = [
            'steps' => $steps,
            'status' => 'running',
            'heartbeat_at' => now()->toIso8601String(),
            'phase_detail' => $line,
        ];
        if ($phase !== null) {
            $data['phase'] = $phase;
            $data['phase_fraction'] = max(0.0, min(1.0, $fraction));
        }

        $this->merge($service, $data, refresh: false);
    }

    /**
     * Update phase/fraction without adding a step. Byte-level progress
     * callbacks land here; writes are throttled to one per second unless forced.
     */
    public function phase(Service $service, string $phase, string $detail = '', float $fraction = 0.0, bool $force = false): void
    {
        $now = microtime(true);
        if (! $force && ($now - ($this->lastWriteAt[$service->id] ?? 0.0)) < 1.0) {
            return;
        }
        $this->lastWriteAt[$service->id] = $now;

        $data = [
            'status' => 'running',
            'phase' => $phase,
            'phase_fraction' => max(0.0, min(1.0, $fraction)),
            'heartbeat_at' => now()->toIso8601String(),
        ];
        if ($detail !== '') {
            $data['phase_detail'] = $detail;
        }

        $this->merge($service, $data);
    }

    /**
     * A fatal error (memory limit, timeout) kills the process before any
     * catch block runs, leaving the convert "running" forever. Register once
     * per process so the row is marked failed with the real reason instead.
     */
    public function failOnFatalShutdown(Service $service): void
    {
        $serviceId = (int) $service->id;
        // Memory the handler can free before it writes: a memory-limit fatal
        // leaves nothing to allocate otherwise.
        $reserve = str_repeat(' ', 256 * 1024);
        register_shutdown_function(function () use ($serviceId, &$reserve): void {
            $reserve = null;
            $error = error_get_last();
            if (! is_array($error)) {
                return;
            }
            $this->recordFatal($serviceId, $error);
        });
    }

    /**
     * @param  array{type: int, message: string, file?: string, line?: int}  $error
     */
    public function recordFatal(int $serviceId, array $error): bool
    {
        if (! in_array((int) ($error['type'] ?? 0), [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return false;
        }

        try {
            @ini_set('memory_limit', '-1');
            $service = Service::query()->find($serviceId);
            if (! $service || ! $this->isActive($this->convertMeta($service))) {
                return false;
            }
            $where = isset($error['file']) ? ' ('.basename((string) $error['file']).':'.(int) ($error['line'] ?? 0).')' : '';
            $this->fail($service, 'PHP stopped: '.trim((string) ($error['message'] ?? 'fatal error')).$where
                .'. Retry convert re-runs from the start; if this is a memory limit, raise it for the queue worker.');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function heartbeat(Service $service): void
    {
        $this->merge($service, ['heartbeat_at' => now()->toIso8601String()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(Service $service, array $data = []): void
    {
        $this->merge($service, array_merge($data, [
            'status' => 'completed',
            'phase' => 'complete',
            'phase_fraction' => 1.0,
            'percent' => 100,
            'completed_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
            'error' => null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function fail(Service $service, string $error, array $data = []): void
    {
        $this->merge($service, array_merge($data, [
            'status' => 'failed',
            'error' => $error,
            'failed_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Merge keys into service_meta.da_convert. Reads the row fresh first so a
     * write from another process (mail pull, sibling job) is never clobbered.
     *
     * @param  array<string, mixed>  $data
     */
    public function merge(Service $service, array $data, bool $refresh = true): void
    {
        if ($refresh) {
            $service->refresh();
        }
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $convert = array_merge(is_array($meta['da_convert'] ?? null) ? $meta['da_convert'] : [], $data);
        if (! array_key_exists('percent', $data)) {
            $convert['percent'] = $this->percentFor($convert);
        }
        $meta['da_convert'] = $convert;
        $service->update(['service_meta' => $meta]);
        $service->refresh();
    }

    /**
     * @param  array<string, mixed>  $convert
     */
    public function isActive(array $convert): bool
    {
        return in_array((string) ($convert['status'] ?? ''), self::STATUSES_ACTIVE, true);
    }

    /**
     * A convert is stuck when nothing has moved for 15 minutes: not its own
     * heartbeat, not the mail pull it drives, not the container deploy it
     * started. Any of the three counts as life.
     *
     * @param  array<string, mixed>  $convert
     */
    public function looksStuck(array $convert, ?Service $service = null): bool
    {
        $newest = $this->newestActivity($convert, $service);
        if ($newest === null) {
            return true;
        }

        return $newest->lt(now()->subMinutes(15));
    }

    /**
     * The most recent sign of life for a convert, across its heartbeat, the
     * mail pull's last write and the newest deploy event since it started.
     *
     * @param  array<string, mixed>  $convert
     */
    public function newestActivity(array $convert, ?Service $service = null): ?Carbon
    {
        $candidates = [];
        foreach (['heartbeat_at', 'started_at', 'queued_at'] as $key) {
            $candidates[] = $convert[$key] ?? null;
        }
        if ($service) {
            $candidates[] = $service->service_meta['mail_pull']['updated_at'] ?? null;
            $startedAt = $convert['started_at'] ?? null;
            if (is_string($startedAt) && $startedAt !== '') {
                try {
                    $latestEvent = ContainerDeploymentEvent::query()
                        ->where('service_id', $service->id)
                        ->where('recorded_at', '>=', Carbon::parse($startedAt))
                        ->max('recorded_at');
                    $candidates[] = $latestEvent;
                } catch (\Throwable) {
                    // A bad timestamp or a missing table never makes a convert look alive.
                }
            }
        }

        $newest = null;
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            try {
                $parsed = Carbon::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
            if ($newest === null || $parsed->gt($newest)) {
                $newest = $parsed;
            }
        }

        return $newest;
    }

    /**
     * Percent from the phase table. While the mail phase runs, the mail pull's
     * own percent fills the mail span so the bar never jumps backwards.
     *
     * @param  array<string, mixed>  $convert
     * @param  array<string, mixed>|null  $mailPull
     */
    public function percentFor(array $convert, ?array $mailPull = null): int
    {
        $status = (string) ($convert['status'] ?? '');
        if ($status === 'completed') {
            return 100;
        }
        if ($status === 'queued') {
            return 1;
        }
        if ($status === '') {
            return 0;
        }

        $table = ($convert['mode'] ?? self::MODE_PRIMARY) === self::MODE_SITE
            ? self::SITE_PHASES
            : self::PRIMARY_PHASES;

        $phase = (string) ($convert['phase'] ?? '');
        if ($phase === '' || ! isset($table[$phase])) {
            // Meta written before phases existed: fall back to the old step heuristic.
            $stepPercent = min(92, 10 + count($convert['steps'] ?? []) * 7);

            return $status === 'running' ? $stepPercent : max(8, min(90, $stepPercent));
        }

        [$base, $end] = $table[$phase];
        $fraction = (float) ($convert['phase_fraction'] ?? 0.0);
        if ($phase === 'mail' && is_array($mailPull) && (int) ($mailPull['percent'] ?? 0) > 0) {
            $fraction = min(1.0, ((int) $mailPull['percent']) / 100);
        }
        $fraction = max(0.0, min(1.0, $fraction));

        return (int) min(99, max($base, round($base + ($end - $base) * $fraction)));
    }

    /**
     * The DirectAdmin node whose per-node convert lock this service's jobs take.
     */
    public function daNodeId(Service $service): int
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $fromLegacy = (int) ($meta['da_legacy']['da_node_id'] ?? 0);
        if ($fromLegacy > 0) {
            return $fromLegacy;
        }
        $convert = $this->convertMeta($service);
        $fromPrevious = (int) ($convert['previous']['node_id'] ?? 0);
        if ($fromPrevious > 0) {
            return $fromPrevious;
        }

        return (int) ($service->node_id ?? 0);
    }

    /**
     * Cache keys of the overlap locks a convert on this service's node can hold.
     *
     * @return list<string>
     */
    public function nodeLockKeys(Service $service): array
    {
        $key = ConvertDirectAdminServiceToContainerJob::nodeLockKey($this->daNodeId($service));

        return [
            ConvertDirectAdminServiceToContainerJob::overlapLockKey($key),
            ConvertDirectAdminProjectSiteJob::overlapLockKey($key),
        ];
    }

    /**
     * True when another job currently holds the node's convert lock. Takes and
     * immediately releases the lock, so it never disturbs a real holder.
     */
    public function nodeLockHeld(Service $service): bool
    {
        foreach ($this->nodeLockKeys($service) as $key) {
            try {
                $lock = Cache::lock($key, 1);
                if (! $lock->get()) {
                    return true;
                }
                $lock->release();
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * Any convert on the same DirectAdmin node that is running and alive.
     * Used before force-releasing a lock: a live run must keep it.
     */
    public function nodeHasLiveConvert(Service $except): bool
    {
        $nodeId = $this->daNodeId($except);
        if ($nodeId <= 0) {
            return false;
        }
        $candidates = Service::query()
            ->where('id', '!=', $except->id)
            ->whereIn('status', ['provisioning', 'pending'])
            ->get();
        foreach ($candidates as $candidate) {
            $convert = $this->convertMeta($candidate);
            if (! $this->isActive($convert) || $this->looksStuck($convert, $candidate)) {
                continue;
            }
            if ($this->daNodeId($candidate) === $nodeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the node's convert locks when no live run owns them. Returns true
     * when a lock was actually released.
     */
    public function releaseStaleNodeLock(Service $service): bool
    {
        if (! $this->nodeLockHeld($service) || $this->nodeHasLiveConvert($service)) {
            return false;
        }
        foreach ($this->nodeLockKeys($service) as $key) {
            try {
                Cache::lock($key)->forceRelease();
            } catch (\Throwable) {
                // Best effort; the lock expires on its own.
            }
        }

        return true;
    }

    /**
     * The project this convert belongs to, whether the row is currently
     * linked to it or was unlinked by a rollback.
     */
    public function projectFor(Service $service): ?CustomerProject
    {
        $service->loadMissing('project');
        $project = $service->project;
        if ($project && $project->recipe_key === DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY) {
            return $project;
        }

        return CustomerProject::query()
            ->where('billing_service_id', $service->id)
            ->where('recipe_key', DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY)
            ->orderByDesc('id')
            ->first();
    }

    public function isSiblingSite(Service $service): bool
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        return ($meta['project_recipe'] ?? null) === DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY
            && ($meta['project_role'] ?? null) === 'site';
    }

    /**
     * Live sibling site services on the primary's project.
     *
     * @return Collection<int, Service>
     */
    public function siblingServices(Service $primary): Collection
    {
        $project = $this->projectFor($primary);
        if (! $project) {
            return new Collection;
        }

        return Service::query()
            ->where('project_id', $project->id)
            ->where('id', '!=', $primary->id)
            ->whereNotIn('status', ['terminated', 'cancelled'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Service $service) => $this->isSiblingSite($service))
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function siblingsFor(Service $primary): array
    {
        return $this->siblingServices($primary)
            ->map(fn (Service $sibling) => $this->siblingRow($sibling))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function siblingRow(Service $sibling): array
    {
        $convert = $this->convertMeta($sibling);
        $convertStatus = (string) ($convert['status'] ?? '');
        $active = $this->isActive($convert);
        $steps = is_array($convert['steps'] ?? null) ? $convert['steps'] : [];
        $meta = is_array($sibling->service_meta) ? $sibling->service_meta : [];
        $serviceStatus = $sibling->status?->value ?? (string) $sibling->status;

        if ($convertStatus === '') {
            $convertStatus = match ($serviceStatus) {
                'pending', 'provisioning' => 'queued',
                'failed' => 'failed',
                'active' => 'completed',
                default => 'idle',
            };
        }

        $label = (string) ($convert['error'] ?? '');
        if ($label === '') {
            $label = (string) ($convert['phase_detail'] ?? ($steps !== [] ? end($steps) : ''));
        }
        if ($label === '') {
            $label = match ($convertStatus) {
                'queued' => 'Waiting for a worker…',
                'completed' => 'Converted',
                default => ucfirst($convertStatus),
            };
        }

        $stuck = $active && ($this->looksStuck($convert, $sibling) || $this->queuedButNotStarting($convert, $sibling));

        return [
            'service_id' => (int) $sibling->id,
            'domain' => (string) ($meta['domain'] ?? $meta['da_legacy']['domain'] ?? $sibling->name),
            'status' => $serviceStatus,
            'convert_status' => $convertStatus,
            'percent' => $this->percentFor($convert),
            'label' => $label,
            'error' => $convert['error'] ?? null,
            'is_active' => $active && ! $stuck,
            'is_stuck' => $stuck,
            'can_retry' => ! $active || $stuck,
            'url' => route('admin.services.show', $sibling),
            'retry_url' => route('admin.services.retry-convert', $sibling),
            'heartbeat_at' => $convert['heartbeat_at'] ?? null,
        ];
    }

    /**
     * For a sibling page: who the billing anchor is.
     *
     * @return array{service_id: int, domain: string, url: string}|null
     */
    public function primaryFor(Service $sibling): ?array
    {
        if (! $this->isSiblingSite($sibling)) {
            return null;
        }
        $meta = is_array($sibling->service_meta) ? $sibling->service_meta : [];
        $anchorId = (int) ($meta['source_service_id'] ?? 0);
        $anchor = $anchorId > 0 ? Service::query()->find($anchorId) : null;
        if (! $anchor && $sibling->project_id) {
            $anchor = CustomerProject::query()->find($sibling->project_id)?->billingService;
        }
        if (! $anchor) {
            return null;
        }
        $anchorMeta = is_array($anchor->service_meta) ? $anchor->service_meta : [];

        return [
            'service_id' => (int) $anchor->id,
            'domain' => (string) ($anchorMeta['domain'] ?? $anchorMeta['da_legacy']['domain'] ?? $anchor->name),
            'url' => route('admin.services.show', $anchor),
        ];
    }

    /**
     * Container deploy events recorded since this convert started.
     *
     * @param  array<string, mixed>  $convert
     * @return list<string>
     */
    public function deployLinesFor(Service $service, array $convert): array
    {
        $startedAt = $convert['started_at'] ?? null;
        if (! is_string($startedAt) || $startedAt === '') {
            return [];
        }

        try {
            $since = Carbon::parse($startedAt);
        } catch (\Throwable) {
            return [];
        }

        return app(ContainerDeployProgressService::class)->linesSince($service, $since);
    }

    /**
     * Primary retry is possible from any terminal state, or when a running
     * convert has stopped heartbeating. Needs the DirectAdmin snapshot to
     * restore the billing row and a target product to re-dispatch.
     *
     * @param  array<string, mixed>  $convert
     */
    public function canRetryPrimary(Service $service, array $convert): bool
    {
        if ($this->isSiblingSite($service)) {
            return false;
        }
        $previous = $convert['previous'] ?? null;
        if (! is_array($previous) || empty($previous['product_id'])) {
            return false;
        }
        $productId = (int) ($convert['options']['product_id'] ?? $convert['target_product_id'] ?? 0);
        if ($productId <= 0) {
            return false;
        }
        $status = (string) ($convert['status'] ?? '');
        if ($status === 'queued' && $this->queuedButNotStarting($convert, $service)) {
            return true;
        }
        if (in_array($status, self::STATUSES_ACTIVE, true)) {
            return $this->looksStuck($convert, $service);
        }

        return in_array($status, ['failed', 'reverted', 'completed'], true);
    }

    /**
     * A queued convert that no worker will ever start: the node lock is held
     * by a dead run (a sync-queue job that cannot take it is dropped without
     * a trace), or it has waited longer than any healthy queue takes.
     *
     * @param  array<string, mixed>  $convert
     */
    public function queuedButNotStarting(array $convert, Service $service): bool
    {
        if ((string) ($convert['status'] ?? '') !== 'queued') {
            return false;
        }
        if ($this->nodeLockHeld($service)) {
            return true;
        }
        $queuedAt = $convert['queued_at'] ?? null;
        if (! is_string($queuedAt) || $queuedAt === '') {
            return true;
        }
        try {
            return Carbon::parse($queuedAt)->lt(now()->subMinutes(10));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Convert half of the operator console payload.
     *
     * @param  array<string, mixed>  $mailPull
     * @return array<string, mixed>
     */
    public function operatorConvertView(Service $service, array $mailPull = []): array
    {
        $convert = $this->convertMeta($service);
        $status = (string) ($convert['status'] ?? '');
        $active = $this->isActive($convert);
        $stuck = $active && ($this->looksStuck($convert, $service) || $this->queuedButNotStarting($convert, $service));
        $isSite = $this->isSiblingSite($service);
        $siblings = $isSite ? [] : $this->siblingsFor($service);
        $siblingsActive = array_values(array_filter($siblings, fn ($row) => $row['is_active']));
        $siblingsDone = array_values(array_filter($siblings, fn ($row) => $row['convert_status'] === 'completed'));
        $siblingsFailed = array_values(array_filter($siblings, fn ($row) => $row['convert_status'] === 'failed'));
        $steps = is_array($convert['steps'] ?? null) ? $convert['steps'] : [];

        $label = (string) ($convert['phase_detail'] ?? '');
        if ($label === '' && $steps !== []) {
            $label = (string) end($steps);
        }
        if ($status === 'failed') {
            $label = (string) ($convert['error'] ?? $label ?: 'Convert failed');
        } elseif ($status === 'queued') {
            $label = $this->nodeLockHeld($service)
                ? 'Queued, but a previous convert run on this DirectAdmin node still holds the node lock, so no worker can start this one. Retry convert clears a stale lock; a lock left by a crashed run expires on its own within 45 minutes.'
                : 'Convert queued. Waiting for a worker on the da-convert queue (the talksasa-da-convert-queue service must be running; the default worker does not take these)…';
        } elseif ($status === 'completed' && $siblingsActive !== []) {
            $label = sprintf('Primary converted. %d of %d extra site(s) still converting…', count($siblingsActive), count($siblings));
        } elseif ($status === 'completed' && $siblingsFailed !== []) {
            $label = sprintf('Primary converted. %d extra site(s) failed — retry them below.', count($siblingsFailed));
        } elseif ($status === 'completed') {
            $label = $label !== '' ? $label : 'Convert completed';
        }

        $canRetry = $isSite
            ? ($convert !== [] || $service->status?->value === 'failed') && (! $active || $stuck)
            : $this->canRetryPrimary($service, $convert);

        return [
            'convert' => $convert !== [] ? $convert : null,
            'convert_status' => $status,
            'convert_active' => $active && ! $stuck,
            'convert_stuck' => $stuck,
            'convert_last_activity_at' => $this->newestActivity($convert, $service)?->toIso8601String(),
            'convert_percent' => $this->percentFor($convert, $mailPull),
            'convert_label' => $label,
            'convert_phase' => (string) ($convert['phase'] ?? ''),
            'convert_steps' => $steps,
            'deploy_lines' => $convert !== [] ? $this->deployLinesFor($service, $convert) : [],
            'siblings' => $siblings,
            'siblings_total' => count($siblings),
            'siblings_done' => count($siblingsDone),
            'siblings_failed' => count($siblingsFailed),
            'siblings_active' => $siblingsActive !== [],
            'is_site' => $isSite,
            'primary' => $isSite ? $this->primaryFor($service) : null,
            'can_retry_convert' => $canRetry,
            'retry_convert_url' => route('admin.services.retry-convert', $service),
            'retry_convert_label' => $isSite ? 'Retry site' : ($status === 'completed' ? 'Re-run convert' : 'Retry convert'),
        ];
    }
}
