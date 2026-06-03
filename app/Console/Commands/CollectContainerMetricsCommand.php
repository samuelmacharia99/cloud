<?php

namespace App\Console\Commands;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\DockerStatsParser;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CollectContainerMetricsCommand extends BaseCronCommand
{
    protected $signature = 'cron:collect-container-metrics';

    protected $description = 'Collect Docker container metrics (CPU, memory, disk, I/O) via docker stats';

    protected function handleCron(): string
    {
        $collected = 0;
        $failed = 0;
        $nodeErrors = [];

        // Get all running deployments grouped by node
        $deployments = ContainerDeployment::where('status', 'running')
            ->with('node')
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
                        $this->collectMetric($ssh, $deployment);
                        $collected++;
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
        if ($failed > 0) {
            $message .= ". Failed: {$failed}";
        }
        if (! empty($nodeErrors)) {
            $message .= '. Errors: '.implode('; ', $nodeErrors);
        }

        return $message;
    }

    /**
     * Collect metrics for a single container
     */
    private function collectMetric(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerName = $deployment->container_name;
        $nameArg = escapeshellarg($containerName);

        $state = trim($ssh->exec(
            "docker inspect -f '{{.State.Running}}' {$nameArg} 2>/dev/null || echo missing",
            10
        ));

        if ($state !== 'true') {
            $deployment->update([
                'last_status_check_at' => now(),
                'last_status_check_output' => $state === 'missing'
                    ? 'Container not found on node during metrics collection'
                    : 'Container not running on node during metrics collection',
            ]);

            return;
        }

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

        // Create metric record
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
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
