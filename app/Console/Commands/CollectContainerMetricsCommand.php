<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Node;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\DockerStatsParser;
use App\Services\SSH\SSHService;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class CollectContainerMetricsCommand extends BaseCronCommand
{
    protected $signature = 'cron:collect-container-metrics';

    protected $description = 'Collect Docker container metrics (CPU, memory, disk, I/O) via docker stats';

    public function __construct(
        private ContainerRuntimeInspector $runtimeInspector,
        private ?Closure $sshFactory = null,
    ) {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $collected = 0;
        $downtimeSamples = 0;
        $skipped = 0;
        $deferred = 0;
        $nodeErrors = [];
        $startedAt = hrtime(true);
        $runtimeBudget = max(30, (int) config('cron.container_metrics.max_runtime_seconds', 210));

        $deployments = ContainerDeployment::query()
            ->whereHas('service', fn ($query) => $query->where('status', ServiceStatus::Active))
            ->where('status', '!=', 'terminated')
            ->with(['node', 'service.product.containerTemplate'])
            ->get()
            ->groupBy('node_id');

        foreach ($deployments as $nodeId => $nodeDeployments) {
            if ($this->runtimeExceeded($startedAt, $runtimeBudget)) {
                $deferred += $nodeDeployments->count();

                continue;
            }

            $node = $nodeDeployments->first()->node;

            if (! $node) {
                $nodeErrors[] = "Node {$nodeId} not found";
                $skipped += $nodeDeployments->count();

                continue;
            }

            $ssh = null;
            try {
                $ssh = $this->sshForNode($node);
                $result = $this->collectNodeMetrics($ssh, $nodeDeployments, $startedAt, $runtimeBudget);
                $collected += $result['collected'];
                $downtimeSamples += $result['downtime'];
                $skipped += $result['skipped'];
                $deferred += $result['deferred'];
            } catch (\Throwable $e) {
                $skipped += $nodeDeployments->count();
                $nodeErrors[] = "Node {$node->hostname}: ".$e->getMessage();
                $this->logNodeFailure($node, $e);
            } finally {
                if ($ssh) {
                    $ssh->disconnect();
                }
            }
        }

        $message = "Collected metrics for {$collected} containers";
        if ($downtimeSamples > 0) {
            $message .= ", {$downtimeSamples} downtime samples";
        }
        if ($skipped > 0) {
            $message .= ". Temporarily skipped: {$skipped}";
        }
        if ($deferred > 0) {
            $message .= ". Deferred to next run: {$deferred}";
        }
        if (! empty($nodeErrors)) {
            $message .= '. Unavailable nodes: '.count($nodeErrors);
        }

        if ($skipped > 0 || $deferred > 0 || $nodeErrors !== []) {
            $this->logDegradedRun($message, $nodeErrors);
        }

        return $message;
    }

    /**
     * @param  Collection<int, ContainerDeployment>  $nodeDeployments
     * @return array{collected: int, downtime: int, skipped: int, deferred: int}
     */
    private function collectNodeMetrics(
        SSHService $ssh,
        Collection $nodeDeployments,
        int $startedAt,
        int $runtimeBudget
    ): array {
        $collected = 0;
        $downtime = 0;
        $skipped = 0;
        $deferred = 0;
        /** @var list<ContainerDeployment> $running */
        $running = [];

        foreach ($nodeDeployments as $deployment) {
            if ($this->runtimeExceeded($startedAt, $runtimeBudget)) {
                $deferred++;

                continue;
            }

            try {
                $inspect = $this->runtimeInspector->inspect($ssh, $deployment->container_name, retry: false);

                if (($inspect['oom_killed'] ?? false) === true) {
                    $this->writeRateLimitedWarning(
                        'container-metrics-oom-warn:'.$deployment->id,
                        now()->addHours(1),
                        'Container was OOM-killed',
                        [
                            'deployment_id' => $deployment->id,
                            'container_name' => $deployment->container_name,
                            'exit_code' => $inspect['exit_code'] ?? null,
                        ]
                    );
                }

                if (! ($inspect['running'] ?? false)) {
                    $detail = ($inspect['missing'] ?? false)
                        ? 'Container not found on node during metrics collection'
                        : (($inspect['oom_killed'] ?? false)
                            ? 'Container stopped after OOM kill during metrics collection'
                            : 'Container not running on node during metrics collection');

                    $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect, $detail);
                    $this->recordDowntimeSample($deployment);
                    $downtime++;

                    continue;
                }

                $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect);
                $running[] = $deployment;
            } catch (\Throwable $e) {
                $skipped++;
                $this->logMetricFailure($deployment, $e);
            }
        }

        if ($running === []) {
            return compact('collected', 'downtime', 'skipped', 'deferred');
        }

        try {
            $statsByName = $this->collectBatchedStats($ssh, $running);
        } catch (\Throwable $e) {
            foreach ($running as $deployment) {
                $skipped++;
                $this->logMetricFailure($deployment, $e);
            }

            return compact('collected', 'downtime', 'skipped', 'deferred');
        }

        foreach ($running as $deployment) {
            if ($this->runtimeExceeded($startedAt, $runtimeBudget)) {
                $deferred++;

                continue;
            }

            try {
                $result = $this->persistUsageSample($ssh, $deployment, $statsByName);
                if ($result === 'usage') {
                    $collected++;
                } elseif ($result === 'downtime') {
                    $downtime++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $this->logMetricFailure($deployment, $e);
            }
        }

        return compact('collected', 'downtime', 'skipped', 'deferred');
    }

    /**
     * @param  list<ContainerDeployment>  $deployments
     * @return array<string, array{cpu: string, mem: string, net: string, block: string}>
     */
    private function collectBatchedStats(SSHService $ssh, array $deployments): array
    {
        $names = array_values(array_unique(array_merge(...array_map(
            fn (ContainerDeployment $deployment) => $this->runtimeContainerNames($deployment),
            $deployments
        ))));

        if ($names === []) {
            return [];
        }

        $timeout = max(
            5,
            (int) config('cron.container_metrics.stats_timeout_seconds', 12) + min(30, count($names))
        );

        $output = trim($ssh->exec(
            "docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}\t{{.BlockIO}}' 2>/dev/null || true",
            $timeout,
            false
        ));

        return DockerStatsParser::parseNamedLines($output);
    }

    /**
     * @param  array<string, array{cpu: string, mem: string, net: string, block: string}>  $statsByName
     * @return 'usage'|'downtime'|null
     */
    private function persistUsageSample(SSHService $ssh, ContainerDeployment $deployment, array $statsByName): ?string
    {
        $containerName = $deployment->container_name;
        $data = $statsByName[$containerName] ?? null;

        if ($data === null) {
            $afterStats = $this->runtimeInspector->inspect($ssh, $containerName, retry: false);
            if (! ($afterStats['running'] ?? false)) {
                $this->runtimeInspector->syncDeploymentStatus(
                    $deployment,
                    $afterStats,
                    'Container stopped while metrics were being sampled'
                );
                $this->recordDowntimeSample($deployment);

                return 'downtime';
            }

            $this->writeRateLimitedWarning(
                'container-metrics-stats-missing:'.$deployment->id,
                now()->addHours(1),
                'docker stats returned no sample for a running container',
                [
                    'deployment_id' => $deployment->id,
                    'container_name' => $containerName,
                ]
            );

            return null;
        }

        $runtimeNames = $this->runtimeContainerNames($deployment);
        $samples = array_values(array_filter(array_map(
            fn (string $name) => $statsByName[$name] ?? null,
            $runtimeNames
        )));

        $cpuPercent = DockerStatsParser::clampCpuPercentage(array_sum(array_map(
            fn (array $sample) => (float) str_replace('%', '', $sample['cpu']),
            $samples
        )));
        $memUsedMb = array_sum(array_map(function (array $sample): int {
            $parts = explode('/', $sample['mem']);

            return DockerStatsParser::parseMemoryToMb(trim($parts[0] ?? '0'));
        }, $samples));
        $included = $deployment->service?->product?->getIncludedContainerLimits(
            $deployment->service?->product?->containerTemplate,
            $deployment
        );
        $memLimitMb = (int) ($included['memory_mb'] ?? $deployment->memory_limit_mb ?? 256);
        $memPercent = $memLimitMb > 0 ? ($memUsedMb / $memLimitMb) * 100 : 0;
        $netRxBytes = $netTxBytes = $blockReadBytes = $blockWriteBytes = 0;
        foreach ($samples as $sample) {
            $netParts = explode('/', $sample['net']);
            $netRxBytes += DockerStatsParser::parseDataToBytes(trim($netParts[0] ?? '0'));
            $netTxBytes += DockerStatsParser::parseDataToBytes(trim($netParts[1] ?? '0'));

            $blockParts = explode('/', $sample['block']);
            $blockReadBytes += DockerStatsParser::parseDataToBytes(trim($blockParts[0] ?? '0'));
            $blockWriteBytes += DockerStatsParser::parseDataToBytes(trim($blockParts[1] ?? '0'));
        }

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => $cpuPercent,
            'memory_used_mb' => $memUsedMb,
            'memory_limit_mb' => $memLimitMb,
            'memory_percentage' => $memPercent,
            'net_io_rx_bytes' => $netRxBytes,
            'net_io_tx_bytes' => $netTxBytes,
            'block_io_read_bytes' => $blockReadBytes,
            'block_io_write_bytes' => $blockWriteBytes,
            'disk_used_gb' => $this->collectDiskUsedGb($ssh, $deployment),
            'recorded_at' => now(),
        ]);

        return 'usage';
    }

    /**
     * Main app plus explicitly named DB/frontend/edge sidecars. New compose files name
     * every billable sidecar; legacy files continue metering the primary until refreshed.
     *
     * @return list<string>
     */
    private function runtimeContainerNames(ContainerDeployment $deployment): array
    {
        $names = [trim((string) $deployment->container_name)];

        try {
            $compose = Yaml::parse((string) $deployment->docker_compose_content);
            foreach (($compose['services'] ?? []) as $service) {
                if (is_array($service) && filled($service['container_name'] ?? null)) {
                    $names[] = trim((string) $service['container_name']);
                }
            }
        } catch (\Throwable) {
            // Primary-container metering is still valid while malformed YAML is repaired.
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function recordDowntimeSample(ContainerDeployment $deployment): void
    {
        $memoryLimitMb = (int) ($deployment->memory_limit_mb ?: 0);
        if ($memoryLimitMb <= 0) {
            $memoryLimitMb = (int) ($deployment->service?->product?->containerTemplate?->required_ram_mb ?? 256);
        }

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_DOWNTIME,
            'cpu_percentage' => 0,
            'memory_used_mb' => $memoryLimitMb,
            'memory_limit_mb' => $memoryLimitMb,
            'memory_percentage' => $memoryLimitMb > 0 ? 100 : 0,
            'net_io_rx_bytes' => 0,
            'net_io_tx_bytes' => 0,
            'block_io_read_bytes' => 0,
            'block_io_write_bytes' => 0,
            'disk_used_gb' => 0,
            'recorded_at' => now(),
        ]);
    }

    private function collectDiskUsedGb(SSHService $ssh, ContainerDeployment $deployment): float
    {
        $lastUsage = ContainerMetric::query()
            ->where('container_deployment_id', $deployment->id)
            ->usageSamples()
            ->latest('recorded_at')
            ->first(['disk_used_gb', 'recorded_at']);

        $intervalMinutes = max(5, (int) config('cron.container_metrics.disk_interval_minutes', 55));
        if (
            $lastUsage
            && (float) $lastUsage->disk_used_gb > 0
            && $lastUsage->recorded_at
            && $lastUsage->recorded_at->gt(now()->subMinutes($intervalMinutes))
        ) {
            return (float) $lastUsage->disk_used_gb;
        }

        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $pathArg = escapeshellarg($containerPath);
        try {
            $output = trim($ssh->exec(
                "du -sb {$pathArg} 2>/dev/null | awk '{print $1}'",
                max(3, (int) config('cron.container_metrics.disk_timeout_seconds', 12)),
                false
            ));

            if ($output !== '' && is_numeric($output)) {
                return round(((float) $output) / (1024 * 1024 * 1024), 4);
            }
        } catch (\Throwable $e) {
            $this->logOptionalDiskFailure($deployment, $e);
        }

        return (float) ($lastUsage?->disk_used_gb ?? 0);
    }

    private function logMetricFailure(ContainerDeployment $deployment, \Throwable $e): void
    {
        $this->writeRateLimitedWarning(
            'container-metrics-warn:'.$deployment->id,
            now()->addHours(6),
            "Failed to collect metrics for deployment {$deployment->id}: {$e->getMessage()}",
            [
                'deployment_id' => $deployment->id,
                'container_name' => $deployment->container_name,
                'node_id' => $deployment->node_id,
                'error' => $e->getMessage(),
            ]
        );
    }

    private function sshForNode(Node $node): SSHService
    {
        return $this->sshFactory
            ? ($this->sshFactory)($node)
            : SSHService::forNode($node);
    }

    private function runtimeExceeded(int $startedAt, int $budgetSeconds): bool
    {
        return (hrtime(true) - $startedAt) / 1_000_000_000 >= $budgetSeconds;
    }

    /**
     * @param  list<string>  $nodeErrors
     */
    private function logDegradedRun(string $message, array $nodeErrors): void
    {
        $this->writeRateLimitedWarning(
            'container-metrics-degraded-run',
            now()->addMinutes(max(5, (int) config('cron.container_metrics.warning_cooldown_minutes', 30))),
            'Container metrics collection completed with partial coverage',
            [
                'summary' => $message,
                'node_errors' => $nodeErrors,
            ]
        );
    }

    private function logNodeFailure(Node $node, \Throwable $e): void
    {
        $this->writeRateLimitedWarning(
            'container-metrics-node-warn:'.$node->id,
            now()->addHours(1),
            'Container metrics node unavailable',
            [
                'node_id' => $node->id,
                'hostname' => $node->hostname,
                'error' => $e->getMessage(),
            ]
        );
    }

    private function logOptionalDiskFailure(ContainerDeployment $deployment, \Throwable $e): void
    {
        $this->writeRateLimitedWarning(
            'container-metrics-disk-warn:'.$deployment->id,
            now()->addHours(6),
            'Disk usage probe failed; retained last known value',
            [
                'deployment_id' => $deployment->id,
                'container_name' => $deployment->container_name,
                'error' => $e->getMessage(),
            ]
        );
    }

    /**
     * Diagnostics must never turn a recoverable sample failure into a cron failure.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeRateLimitedWarning(
        string $cacheKey,
        \DateTimeInterface $expiresAt,
        string $message,
        array $context
    ): void {
        try {
            $firstOccurrence = Cache::add($cacheKey, true, $expiresAt);
        } catch (\Throwable) {
            $firstOccurrence = true;
        }

        try {
            if ($firstOccurrence) {
                Log::warning($message, $context);
            } else {
                Log::debug($message, $context);
            }
        } catch (\Throwable) {
            // Metrics collection remains operational even when its diagnostic sink fails.
        }
    }
}
