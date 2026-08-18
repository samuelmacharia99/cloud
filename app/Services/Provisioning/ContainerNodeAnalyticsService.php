<?php

namespace App\Services\Provisioning;

use App\Models\ContainerMetric;
use App\Models\Node;
use App\Models\NodeMonitoring;
use Illuminate\Support\Collection;

/**
 * Operator analytics for a container host: live vs sold capacity, fleet health,
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
        $aggregates = $this->metricAggregates($deployments->pluck('id')->all());
        $metricsStale = $fleet['total'] > 0 && ! $this->hasFreshMetrics($deployments->pluck('id')->all());
        $topConsumers = $this->topConsumers($deployments, $aggregates);
        $attention = $this->attentionList($deployments);
        $insights = $this->insights($node, $capacity, $fleet, $metricsStale, $attention);
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
     * @param  list<int>  $deploymentIds
     * @return Collection<int, object>
     */
    private function metricAggregates(array $deploymentIds): Collection
    {
        if ($deploymentIds === []) {
            return collect();
        }

        return ContainerMetric::query()
            ->usageSamples()
            ->whereIn('container_deployment_id', $deploymentIds)
            ->where('recorded_at', '>=', now()->subDay())
            ->groupBy('container_deployment_id')
            ->selectRaw('container_deployment_id, AVG(cpu_percentage) as avg_cpu, MAX(memory_used_mb) as peak_memory_mb, MAX(disk_used_gb) as peak_disk_gb')
            ->get()
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
     * @param  Collection<int, object>  $aggregates
     * @return list<array<string, mixed>>
     */
    private function topConsumers(Collection $deployments, Collection $aggregates): array
    {
        return $deployments
            ->map(function ($deployment) use ($aggregates) {
                $row = $aggregates->get($deployment->id);
                $service = $deployment->service;

                return [
                    'service_id' => $service?->id,
                    'service_name' => $service?->name ?: ($service?->product?->name ?? $deployment->container_name),
                    'customer_name' => $service?->user?->name,
                    'container_name' => $deployment->container_name,
                    'status' => $deployment->status,
                    'avg_cpu' => $row ? round((float) $row->avg_cpu, 1) : null,
                    'peak_memory_mb' => $row ? (int) $row->peak_memory_mb : null,
                    'peak_disk_gb' => $row ? round((float) $row->peak_disk_gb, 2) : null,
                ];
            })
            ->filter(fn (array $row) => $row['avg_cpu'] !== null)
            ->sortByDesc(fn (array $row) => $row['avg_cpu'])
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
    private function insights(Node $node, array $capacity, array $fleet, bool $metricsStale, array $attention): array
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

        if ($capacity['pressure_percent'] >= $threshold) {
            $drivers = implode(', ', $capacity['drivers'] ?: ['capacity']);
            $insights[] = [
                'severity' => 'warning',
                'title' => 'Scale-out pressure at '.$capacity['pressure_percent'].'%',
                'detail' => 'Driven by '.$drivers.'. Placement uses live CPU/RAM and sold disk — not plan CPU/RAM oversell.',
            ];
        }

        if ($capacity['reserved']['cpu'] > 100 && $capacity['live']['cpu'] < $threshold) {
            $insights[] = [
                'severity' => 'info',
                'title' => 'Plan CPU is oversold at '.$capacity['reserved']['cpu'].'%',
                'detail' => 'Customers are sold '.$capacity['reserved_absolute']['cpu_cores'].' cores on a '.$node->cpu_cores.'-core host. Live CPU is '.$capacity['live']['cpu'].'%. This is expected for elastic plans and does not by itself require a new node.',
            ];
        }

        if ($capacity['reserved']['ram'] > 100 && $capacity['live']['ram'] < $threshold) {
            $insights[] = [
                'severity' => 'info',
                'title' => 'Plan RAM is oversold at '.$capacity['reserved']['ram'].'%',
                'detail' => 'Sold '.$capacity['reserved_absolute']['ram_gb'].' GB on a '.$node->ram_gb.' GB host. Live RAM is '.$capacity['live']['ram'].'%.',
            ];
        }

        if ($capacity['reserved']['storage'] >= $threshold) {
            $insights[] = [
                'severity' => 'warning',
                'title' => 'Sold disk is '.$capacity['reserved']['storage'].'% of this host',
                'detail' => $capacity['reserved_absolute']['storage_gb'].' GB sold of '.$node->storage_gb.' GB. Disk reservations are hard — add a host before placing more storage-heavy apps.',
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
                'detail' => 'No docker stats samples in the last two hours. Top consumers below may be empty until cron:collect-container-metrics succeeds.',
            ];
        }

        if ($insights === [] && $fleet['total'] > 0) {
            $insights[] = [
                'severity' => 'info',
                'title' => 'Host has headroom',
                'detail' => 'Live pressure is '.$capacity['pressure_percent'].'% (alert at '.$threshold.'%). '.$fleet['running'].' of '.$fleet['total'].' runtimes are running.',
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
