<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\DockerStatsParser;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CollectContainerMetricsCommand extends BaseCronCommand
{
    protected $signature = 'cron:collect-container-metrics';

    protected $description = 'Collect Docker container metrics (CPU, memory, disk, I/O) via docker stats';

    public function __construct(
        private ContainerRuntimeInspector $runtimeInspector,
    ) {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $collected = 0;
        $downtimeSamples = 0;
        $failed = 0;
        $nodeErrors = [];

        $deployments = ContainerDeployment::query()
            ->whereHas('service', fn ($query) => $query->where('status', ServiceStatus::Active))
            ->where('status', '!=', 'terminated')
            ->with(['node', 'service'])
            ->get()
            ->groupBy('node_id');

        foreach ($deployments as $nodeId => $nodeDeployments) {
            $node = $nodeDeployments->first()->node;

            if (! $node) {
                $nodeErrors[] = "Node {$nodeId} not found";

                continue;
            }

            $ssh = null;
            try {
                $ssh = SSHService::forNode($node);

                foreach ($nodeDeployments as $deployment) {
                    try {
                        $result = $this->collectMetric($ssh, $deployment);
                        if ($result === 'usage') {
                            $collected++;
                        } elseif ($result === 'downtime') {
                            $downtimeSamples++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->logMetricFailure($deployment, $e);
                    }
                }
            } catch (\Exception $e) {
                $failed += $nodeDeployments->count();
                $nodeErrors[] = "Node {$node->hostname}: ".$e->getMessage();
                Log::error("SSH connection error for node {$node->id}: ".$e->getMessage());
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
        if ($failed > 0) {
            $message .= ". Failed: {$failed}";
        }
        if (! empty($nodeErrors)) {
            $message .= '. Errors: '.implode('; ', $nodeErrors);
        }

        return $message;
    }

    /**
     * @return 'usage'|'downtime'|null
     */
    private function collectMetric(SSHService $ssh, ContainerDeployment $deployment): ?string
    {
        $containerName = $deployment->container_name;
        $inspect = $this->runtimeInspector->inspect($ssh, $containerName);

        if (($inspect['oom_killed'] ?? false) === true) {
            Log::warning('Container was OOM-killed', [
                'deployment_id' => $deployment->id,
                'container_name' => $containerName,
                'exit_code' => $inspect['exit_code'] ?? null,
            ]);
        }

        if (! ($inspect['running'] ?? false)) {
            $detail = ($inspect['missing'] ?? false)
                ? 'Container not found on node during metrics collection'
                : (($inspect['oom_killed'] ?? false)
                    ? 'Container stopped after OOM kill during metrics collection'
                    : 'Container not running on node during metrics collection');

            $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect, $detail);
            $this->recordDowntimeSample($deployment, $inspect);

            return 'downtime';
        }

        $this->runtimeInspector->syncDeploymentStatus($deployment, $inspect);

        $nameArg = escapeshellarg($containerName);
        $output = trim($ssh->exec(
            "docker stats {$nameArg} --no-stream --format '{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}\t{{.BlockIO}}' 2>/dev/null || true",
            15
        ));

        $data = DockerStatsParser::parseLine($output, $containerName);

        $cpuPercent = (float) str_replace('%', '', $data['cpu']);

        $memParts = explode('/', $data['mem']);
        $memUsedStr = trim($memParts[0] ?? '0');
        $memLimitStr = trim($memParts[1] ?? '0');

        $memUsedMb = DockerStatsParser::parseMemoryToMb($memUsedStr);
        $memLimitMb = DockerStatsParser::parseMemoryToMb($memLimitStr);
        $memPercent = $memLimitMb > 0 ? ($memUsedMb / $memLimitMb) * 100 : 0;

        $netParts = explode('/', $data['net']);
        $netRxStr = trim($netParts[0] ?? '0');
        $netTxStr = trim($netParts[1] ?? '0');

        $netRxBytes = DockerStatsParser::parseDataToBytes($netRxStr);
        $netTxBytes = DockerStatsParser::parseDataToBytes($netTxStr);

        $blockParts = explode('/', $data['block']);
        $blockReadStr = trim($blockParts[0] ?? '0');
        $blockWriteStr = trim($blockParts[1] ?? '0');

        $blockReadBytes = DockerStatsParser::parseDataToBytes($blockReadStr);
        $blockWriteBytes = DockerStatsParser::parseDataToBytes($blockWriteStr);

        $diskUsedGb = $this->collectDiskUsedGb($ssh, $deployment);

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
            'disk_used_gb' => $diskUsedGb,
            'recorded_at' => now(),
        ]);

        return 'usage';
    }

    /**
     * @param  array<string, mixed>  $inspect
     */
    private function recordDowntimeSample(ContainerDeployment $deployment, array $inspect): void
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
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $pathArg = escapeshellarg($containerPath);
        $output = trim($ssh->exec("du -sb {$pathArg} 2>/dev/null | awk '{print $1}'", 15));

        if ($output === '' || ! is_numeric($output)) {
            return 0.0;
        }

        return round(((float) $output) / (1024 * 1024 * 1024), 4);
    }

    private function logMetricFailure(ContainerDeployment $deployment, \Throwable $e): void
    {
        $cacheKey = 'container-metrics-warn:'.$deployment->id;
        $context = [
            'deployment_id' => $deployment->id,
            'container_name' => $deployment->container_name,
            'node_id' => $deployment->node_id,
            'error' => $e->getMessage(),
        ];

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, true, now()->addHours(6));
            Log::warning("Failed to collect metrics for deployment {$deployment->id}: {$e->getMessage()}", $context);
        } else {
            Log::debug("Failed to collect metrics for deployment {$deployment->id}: {$e->getMessage()}", $context);
        }
    }
}
