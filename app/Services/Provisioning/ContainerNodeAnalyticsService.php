<?php

namespace App\Services\Provisioning;

use App\Models\ContainerMetric;
use App\Models\Node;
use App\Models\NodeMonitoring;
use Illuminate\Support\Collection;

/**
 * Operator analytics for a container host: live utilization, fleet health,
 * noisy neighbors, and 24h host trends from already-collected samples.
 */
class ContainerNodeAnalyticsService
{
    public function __construct(
        private ContainerNodeCapacityService $capacity,
    ) {}

    /**
     * @return array{
     *     capacity: array<string, mixed>,
     *     fleet: array{total: int, running: int, stopped: int, failed: int, deploying: int, other: int},
     *     insights: list<array{severity: string, title: string, detail: string}>,
     *     top_consumers: list<array<string, mixed>>,
     *     attention: list<array<string, mixed>>,
     *     chart: array{labels: list<string>, cpu: list<int>, ram: list<int>, storage: list<int>},
     *     metrics_stale: bool,
     *     live_pressure: int,
     *     scale_out_threshold: int
     * }
     */
    public function forNode(Node $node): array
    {
        $capacity = $this->capacity->evaluate($node);
        $node->containerDeployments->loadMissing(['service.user', 'service.product']);
        $deployments = $node->containerDeployments
            ->where('status', '!=', 'terminated')
            ->values();

        $fleet = $this->fleetCounts($deployments);
        $aggregates = $this->latestMetrics($deployments->pluck('id')->all());
        $metricsStale = $fleet['total'] > 0 && ! $this->hasFreshMetrics($deployments->pluck('id')->all());
        $topConsumers = $this->topConsumers($deployments, $aggregates);
        $attention = $this->attentionList($deployments);
        $livePressure = max(
            $capacity['live']['cpu'],
            $capacity['live']['ram'],
            $capacity['live']['storage'],
        );
        $insights = $this->insights($node, $capacity, $fleet, $metricsStale, $attention, $livePressure);
        $threshold = max(1, min(99, (int) config(
            'containers.elastic_resources.scale_out_threshold_percent',
            70
        )));

        return [
            'capacity' => $capacity,
            'fleet' => $fleet,
            'insights' => $insights,
            'top_consumers' => $topConsumers,
            'attention' => $attention,
            'chart' => $this->chartSeries($node),
            'metrics_stale' => $metricsStale,
            'live_pressure' => $livePressure,
            'scale_out_threshold' => $threshold,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $deployments
     * @return array{total: int, running: int, stopped: int, failed: int, deploying: int, other: int}
     */
    private function fleetCounts(Collection $deployments): array
    {
        $counts = [
            'total' => $deployments->count(),
            'running' => 0,
            'stopped' => 0,
            'failed' => 0,
            'deploying' => 0,
            'other' => 0,
        ];

        foreach ($deployments as $deployment) {
            $status = (string) $deployment->status;
            if ($status === 'suspended') {
                $status = 'stopped';
            }

            if (array_key_exists($status, $counts) && $status !== 'total' && $status !== 'other') {
                $counts[$status]++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    /**
     * Latest docker-stats sample per runtime (live use, not 24h average).
     *
     * @param  list<int>  $deploymentIds
     * @return Collection<int, ContainerMetric>
     */
    private function latestMetrics(array $deploymentIds): Collection
    {
        if ($deploymentIds === []) {
            return collect();
        }

        return ContainerMetric::query()
            ->usageSamples()
            ->whereIn('container_deployment_id', $deploymentIds)
            ->where('recorded_at', '>=', now()->subDay())
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->unique('container_deployment_id')
            ->keyBy('container_deployment_id');
    }

    /**
     * @param  list<int>  $deploymentIds
     */
    private function hasFreshMetrics(array $deploymentIds): bool
    {
        if ($deploymentIds === []) {
            return false;
        }

        return ContainerMetric::query()
            ->usageSamples()
            ->whereIn('container_deployment_id', $deploymentIds)
            ->where('recorded_at', '>=', now()->subHours(2))
            ->exists();
    }

    /**
     * @param  Collection<int, mixed>  $deployments
     * @param  Collection<int, ContainerMetric>  $latest
     * @return list<array<string, mixed>>
     */
    private function topConsumers(Collection $deployments, Collection $latest): array
    {
        return $deployments
            ->map(function ($deployment) use ($latest) {
                $row = $latest->get($deployment->id);
                $service = $deployment->service;

                return [
                    'service_id' => $service?->id,
                    'service_name' => $service?->name ?: ($service?->product?->name ?? $deployment->container_name),
                    'customer_name' => $service?->user?->name,
                    'container_name' => $deployment->container_name,
                    'status' => $deployment->status,
                    'cpu' => $row ? round((float) $row->cpu_percentage, 1) : null,
                    'memory_mb' => $row ? (int) $row->memory_used_mb : null,
                    'disk_gb' => $row ? round((float) $row->disk_used_gb, 2) : null,
                    'sampled_at' => $row?->recorded_at,
                ];
            })
            ->filter(fn (array $row) => $row['cpu'] !== null)
            ->sortByDesc(fn (array $row) => $row['cpu'])
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $deployments
     * @return list<array<string, mixed>>
     */
    private function attentionList(Collection $deployments): array
    {
        return $deployments
            ->filter(fn ($deployment) => in_array($deployment->status, ['failed', 'stopped', 'suspended'], true))
            ->map(function ($deployment) {
                $service = $deployment->service;

                return [
                    'service_id' => $service?->id,
                    'service_name' => $service?->name ?: ($service?->product?->name ?? $deployment->container_name),
                    'customer_name' => $service?->user?->name,
                    'status' => $deployment->status,
                    'container_name' => $deployment->container_name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $capacity
     * @param  array{total: int, running: int, stopped: int, failed: int, deploying: int, other: int}  $fleet
     * @param  list<array<string, mixed>>  $attention
     * @return list<array{severity: string, title: string, detail: string}>
     */
    private function insights(Node $node, array $capacity, array $fleet, bool $metricsStale, array $attention, int $livePressure): array
    {
        $insights = [];
        $threshold = max(1, min(99, (int) config(
            'containers.elastic_resources.scale_out_threshold_percent',
            70
        )));

        if ($node->status === 'offline') {
            $insights[] = [
                'severity' => 'critical',
                'title' => 'Host is offline',
                'detail' => 'SSH polling cannot reach this node. New placements should wait until it is healthy.',
            ];
        } elseif ($node->status === 'degraded') {
            $insights[] = [
                'severity' => 'warning',
                'title' => 'Host is degraded',
                'detail' => 'Live RAM, disk, or a recent poll failure crossed the warning threshold.',
            ];
        }

        if ($node->last_heartbeat_at === null) {
            $insights[] = [
                'severity' => 'warning',
                'title' => 'No health poll yet',
                'detail' => 'Use Test Health or wait for the two-minute node poll before trusting live CPU and RAM.',
            ];
        } elseif ($node->last_heartbeat_at->lt(now()->subMinutes(5))) {
            $insights[] = [
                'severity' => 'warning',
                'title' => 'Health poll is stale',
                'detail' => 'Last successful poll was '.$node->last_heartbeat_at->diffForHumans().'. Live gauges may be outdated.',
            ];
        }

        if ($livePressure >= $threshold) {
            $hot = [];
            foreach (['CPU' => 'cpu', 'RAM' => 'ram', 'disk' => 'storage'] as $label => $key) {
                if ($capacity['live'][$key] >= $threshold) {
                    $hot[] = $label.' '.$capacity['live'][$key].'%';
                }
            }
            $insights[] = [
                'severity' => 'warning',
                'title' => 'Live usage is '.$livePressure.'%',
                'detail' => 'Highest live metric: '.implode(', ', $hot ?: ['pressure']).'. This is actual host use, not plan allowances sold to customers.',
            ];
        }

        $failed = collect($attention)->where('status', 'failed')->count();
        if ($failed > 0) {
            $insights[] = [
                'severity' => 'warning',
                'title' => $failed.' container'.($failed === 1 ? '' : 's').' failed',
                'detail' => 'Open the service page to inspect logs, restart, or migrate the runtime.',
            ];
        }

        if ($metricsStale) {
            $insights[] = [
                'severity' => 'warning',
                'title' => 'Container stats are stale',
                'detail' => 'No docker stats samples in the last two hours. Per-app live usage below may be empty until cron:collect-container-metrics succeeds.',
            ];
        }

        $heartbeatFresh = $node->last_heartbeat_at !== null
            && $node->last_heartbeat_at->gte(now()->subMinutes(5));
        if (
            $node->status === 'online'
            && $heartbeatFresh
            && $livePressure < $threshold
            && $fleet['total'] > 0
        ) {
            $insights[] = [
                'severity' => 'info',
                'title' => 'Host has live headroom',
                'detail' => 'Live use is CPU '.$capacity['live']['cpu'].'%, RAM '.$capacity['live']['ram'].'%, disk '.$capacity['live']['storage'].'% (alert at '.$threshold.'%). '.$fleet['running'].' of '.$fleet['total'].' runtimes are running.',
            ];
        }

        return $insights;
    }

    /**
     * @return array{labels: list<string>, cpu: list<int>, ram: list<int>, storage: list<int>}
     */
    private function chartSeries(Node $node): array
    {
        $rows = $node->monitoring()
            ->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')
            ->get();

        if ($rows->isEmpty()) {
            return ['labels' => [], 'cpu' => [], 'ram' => [], 'storage' => []];
        }

        $step = max(1, (int) ceil($rows->count() / 96));
        $labels = [];
        $cpu = [];
        $ram = [];
        $storage = [];

        foreach ($rows->values() as $index => $row) {
            if ($index % $step !== 0 && $index !== $rows->count() - 1) {
                continue;
            }

            /** @var NodeMonitoring $row */
            $labels[] = $row->recorded_at->format('H:i');
            $cpu[] = (int) ($row->cpu_percentage ?? 0);
            $ram[] = $row->getRamUsagePercentage();
            $storage[] = $row->getStorageUsagePercentage();
        }

        return [
            'labels' => $labels,
            'cpu' => $cpu,
            'ram' => $ram,
            'storage' => $storage,
        ];
    }
}
