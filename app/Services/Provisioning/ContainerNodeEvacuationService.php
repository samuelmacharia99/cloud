<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Node;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * When a container host is live-full, move the heaviest bursting app to the
 * quietest host that still has headroom. Plan overage is billed; the app is
 * not killed. DNS A records follow the new node when we manage the zone.
 */
class ContainerNodeEvacuationService
{
    public function __construct(
        private ContainerNodeCapacityService $capacity,
        private ContainerDeploymentService $deployment,
        private ContainerMigrationService $migration,
    ) {}

    /**
     * @return array{migrated: list<int>, skipped: list<string>, failed: list<string>}
     */
    public function evacuatePressuredHosts(): array
    {
        $maxPerRun = max(1, (int) config('containers.elastic_resources.evacuate_max_per_run', 1));
        $lockSeconds = max(60, (int) config('containers.elastic_resources.evacuate_lock_seconds', 1800));
        $lock = Cache::lock('container-node-evacuate', $lockSeconds);

        if (! $lock->get()) {
            return [
                'migrated' => [],
                'skipped' => ['Evacuation already running.'],
                'failed' => [],
            ];
        }

        try {
            return $this->runEvacuation($maxPerRun);
        } finally {
            $lock->release();
        }
    }

    public function relocateIfNeeded(Service $service, string $reason = 'node_capacity'): bool
    {
        $service->loadMissing([
            'containerDeployment.node',
            'product.containerTemplate',
        ]);
        $deployment = $service->containerDeployment;
        $source = $deployment?->node;
        $template = $this->deployment->resolveContainerTemplate($service)
            ?? $service->product?->containerTemplate;

        if (! $source || ! $template) {
            return false;
        }

        if (! $this->deployment->shouldRelocateOffHost($source, $service, $template)) {
            return false;
        }

        try {
            $target = $this->deployment->selectLeastLoadedHost($template, $service, $source->id);
        } catch (\Throwable $e) {
            Log::warning('Could not relocate container; no quieter host has capacity', [
                'service_id' => $service->id,
                'source_node_id' => $source->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $this->migrateService($service, $target, $reason);
    }

    /**
     * @return array{migrated: list<int>, skipped: list<string>, failed: list<string>}
     */
    private function runEvacuation(int $maxPerRun): array
    {
        $migrated = [];
        $skipped = [];
        $failed = [];
        $cooldownHours = max(1, (int) config('containers.elastic_resources.evacuate_cooldown_hours', 6));

        $sources = Node::query()
            ->where('type', 'container_host')
            ->where('is_active', true)
            ->whereIn('status', ['online', 'degraded'])
            ->orderBy('name')
            ->get();

        foreach ($sources as $source) {
            if (count($migrated) >= $maxPerRun) {
                break;
            }

            if (! $this->capacity->needsScaleOut($source)) {
                continue;
            }

            $candidate = $this->pickVictim($source, $cooldownHours);
            if (! $candidate) {
                $skipped[] = "Node {$source->hostname}: no eligible container to move.";

                continue;
            }

            $template = $this->deployment->resolveContainerTemplate($candidate)
                ?? $candidate->product?->containerTemplate;
            if (! $template) {
                $skipped[] = "Service {$candidate->id}: missing container template.";

                continue;
            }

            try {
                $target = $this->deployment->selectLeastLoadedHost($template, $candidate, $source->id);
            } catch (\Throwable $e) {
                $skipped[] = "Node {$source->hostname}: ".$e->getMessage();

                continue;
            }

            if ($this->migrateService($candidate, $target, 'node_capacity')) {
                $migrated[] = $candidate->id;
            } else {
                $failed[] = "Service {$candidate->id} could not be moved from {$source->hostname}.";
            }
        }

        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    private function pickVictim(Node $source, int $cooldownHours): ?Service
    {
        $deployments = ContainerDeployment::query()
            ->where('node_id', $source->id)
            ->whereIn('status', ['running', 'stopped'])
            ->whereHas('service', fn ($query) => $query->whereIn('status', ['active', 'suspended']))
            ->with(['service.product.containerTemplate'])
            ->get();

        $ranked = $deployments
            ->filter(function (ContainerDeployment $deployment) use ($cooldownHours) {
                if ($deployment->migrated_at && $deployment->migrated_at->gt(now()->subHours($cooldownHours))) {
                    return false;
                }

                return $deployment->service !== null;
            })
            ->sortByDesc(fn (ContainerDeployment $deployment) => $this->liveWeight($deployment))
            ->values();

        return $ranked->first()?->service;
    }

    private function liveWeight(ContainerDeployment $deployment): float
    {
        $latest = ContainerMetric::query()
            ->where('container_deployment_id', $deployment->id)
            ->usageSamples()
            ->orderByDesc('recorded_at')
            ->first();

        if ($latest) {
            return ((float) $latest->memory_used_mb) + ((float) $latest->cpu_percentage * 10);
        }

        return (float) ($deployment->memory_limit_mb ?? 0) + ((float) ($deployment->cpu_limit ?? 0) * 100);
    }

    private function migrateService(Service $service, Node $target, string $reason): bool
    {
        $lockSeconds = max(60, (int) config('containers.elastic_resources.evacuate_lock_seconds', 1800));
        $lock = Cache::lock('container-evacuate-service:'.$service->id, $lockSeconds);
        if (! $lock->get()) {
            return false;
        }

        try {
            $this->migration->migrate($service, $target, $reason);
            Log::info('Relocated container off a pressured host', [
                'service_id' => $service->id,
                'target_node_id' => $target->id,
                'reason' => $reason,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to relocate container off a pressured host', [
                'service_id' => $service->id,
                'target_node_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }
}
