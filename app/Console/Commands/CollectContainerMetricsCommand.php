<?php

namespace App\Console\Commands;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

class CollectContainerMetricsCommand extends BaseCronCommand
{
    protected $signature = 'cron:collect-container-metrics';
    protected $description = 'Collect Docker container metrics (CPU, memory, I/O) via docker stats';

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

            if (!$node) {
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
                    } catch (\Exception $e) {
                        $failed++;
                        Log::warning("Failed to collect metrics for deployment {$deployment->id}: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                $failed += $nodeDeployments->count();
                $nodeErrors[] = "Node {$node->hostname}: " . $e->getMessage();
                Log::error("SSH connection error for node {$node->id}: " . $e->getMessage());
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
        if (!empty($nodeErrors)) {
            $message .= ". Errors: " . implode('; ', $nodeErrors);
        }

        return $message;
    }

    /**
     * Collect metrics for a single container
     */
    private function collectMetric(SSHService $ssh, ContainerDeployment $deployment): void
    {
        $containerName = $deployment->container_name;

        // Run docker stats and capture output
        $output = $ssh->exec(
            "docker stats {$containerName} --no-stream --format '{\"cpu\":\"{{.CPUPerc}}\",\"mem\":\"{{.MemUsage}}\",\"net\":\"{{.NetIO}}\",\"block\":\"{{.BlockIO}}\"}' 2>/dev/null || echo '{}'",
            10
        );

        $data = json_decode(trim($output), true);
        if (!$data || empty($data['cpu'])) {
            throw new \Exception("Failed to parse docker stats for {$containerName}");
        }

        // Parse CPU percentage (e.g., "12.34%")
        $cpuPercent = (float) str_replace('%', '', $data['cpu'] ?? '0');

        // Parse memory usage (e.g., "256MiB / 512MiB")
        $memParts = explode('/', $data['mem'] ?? '0 / 0');
        $memUsedStr = trim($memParts[0] ?? '0');
        $memLimitStr = trim($memParts[1] ?? '0');

        $memUsedMb = $this->parseMemoryToMb($memUsedStr);
        $memLimitMb = $this->parseMemoryToMb($memLimitStr);
        $memPercent = $memLimitMb > 0 ? ($memUsedMb / $memLimitMb) * 100 : 0;

        // Parse network I/O (e.g., "1.2MB / 3.4MB")
        $netParts = explode('/', $data['net'] ?? '0 / 0');
        $netRxStr = trim($netParts[0] ?? '0');
        $netTxStr = trim($netParts[1] ?? '0');

        $netRxBytes = $this->parseDataToBytes($netRxStr);
        $netTxBytes = $this->parseDataToBytes($netTxStr);

        // Parse block I/O (e.g., "1.2MB / 3.4MB")
        $blockParts = explode('/', $data['block'] ?? '0 / 0');
        $blockReadStr = trim($blockParts[0] ?? '0');
        $blockWriteStr = trim($blockParts[1] ?? '0');

        $blockReadBytes = $this->parseDataToBytes($blockReadStr);
        $blockWriteBytes = $this->parseDataToBytes($blockWriteStr);

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
            'recorded_at' => now(),
        ]);
    }

    /**
     * Convert memory string (e.g., "256MiB", "1.2GiB") to MB
     */
    private function parseMemoryToMb(string $value): int
    {
        $value = strtoupper(trim($value));

        if (strpos($value, 'GIB') !== false) {
            return (int) (floatval($value) * 1024);
        } elseif (strpos($value, 'MIB') !== false) {
            return (int) floatval($value);
        } elseif (strpos($value, 'KIB') !== false) {
            return (int) (floatval($value) / 1024);
        } elseif (strpos($value, 'B') !== false) {
            return (int) (floatval($value) / 1024 / 1024);
        }

        return (int) floatval($value);
    }

    /**
     * Convert data size string (e.g., "1.2MB", "256KB") to bytes
     */
    private function parseDataToBytes(string $value): int
    {
        $value = strtoupper(trim($value));

        if (strpos($value, 'GB') !== false) {
            return (int) (floatval($value) * 1024 * 1024 * 1024);
        } elseif (strpos($value, 'MB') !== false) {
            return (int) (floatval($value) * 1024 * 1024);
        } elseif (strpos($value, 'KB') !== false) {
            return (int) (floatval($value) * 1024);
        } elseif (strpos($value, 'B') !== false) {
            return (int) floatval($value);
        }

        return (int) floatval($value);
    }
}
