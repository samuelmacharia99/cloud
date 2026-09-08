<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\NodeMonitoring;
use App\Services\SSH\SSHService;
use Throwable;

class NodeHardwareProbeService
{
    public const TIMEOUT_SECONDS = 15;

    /**
     * SSH into the node, persist detected hardware, and record a monitoring sample.
     *
     * @return array{
     *     success: bool,
     *     reason: string,
     *     message: string,
     *     cpu_cores: int,
     *     ram_gb: int,
     *     storage_gb: int,
     *     ram_used_gb: int,
     *     storage_used_gb: int,
     *     cpu_used: int,
     *     uptime: string,
     *     load_average: float
     * }
     */
    public function probeAndPersist(Node $node, ?SSHService $ssh = null): array
    {
        if (! filled($node->ssh_username)) {
            return $this->failure(
                'credentials',
                'SSH username is not configured. Please edit the node and set the SSH username.',
            );
        }

        if (! filled($node->ssh_password)) {
            return $this->failure(
                'credentials',
                'SSH password is not configured. Please edit the node and set the SSH password (root login on port '.$node->ssh_port.').',
            );
        }

        $ownsConnection = $ssh === null;

        try {
            $ssh ??= SSHService::forNode($node);
            $metrics = $this->collect($node, $ssh);

            if ($metrics['cpu_cores'] < 1 || $metrics['ram_gb'] < 1) {
                return $this->failure(
                    'parse',
                    'SSH connected but the host did not return usable CPU or RAM figures.',
                );
            }

            $this->persist($node, $metrics);

            return array_merge($this->emptyMetrics(), $metrics, [
                'success' => true,
                'reason' => 'ok',
                'message' => 'Hardware specs detected.',
            ]);
        } catch (Throwable $e) {
            return $this->failure('ssh', $e->getMessage());
        } finally {
            if ($ownsConnection && $ssh !== null) {
                try {
                    $ssh->disconnect();
                } catch (Throwable) {
                    // Connection may never have opened.
                }
            }
        }
    }

    /**
     * @param  array{
     *     success: bool,
     *     cpu_cores: int,
     *     ram_gb: int,
     *     storage_gb: int,
     *     ram_used_gb: int,
     *     storage_used_gb: int,
     *     cpu_used: int,
     *     uptime: string,
     *     load_average: float
     * }  $result
     */
    public function formatHealthMessage(array $result): string
    {
        $ramPercent = $result['ram_gb'] > 0
            ? (int) ($result['ram_used_gb'] / $result['ram_gb'] * 100)
            : 0;
        $storagePercent = $result['storage_gb'] > 0
            ? (int) ($result['storage_used_gb'] / $result['storage_gb'] * 100)
            : 0;

        $message = "Node health test passed! ✓\n\n";
        $message .= "📊 Metrics:\n";
        $message .= "  CPU: {$result['cpu_used']}% ({$result['cpu_cores']} cores)\n";
        $message .= "  RAM: {$result['ram_used_gb']}/{$result['ram_gb']} GB ({$ramPercent}%)\n";
        if ($result['storage_gb'] > 0) {
            $message .= "  Storage: {$result['storage_used_gb']}/{$result['storage_gb']} GB ({$storagePercent}%)\n";
        } else {
            $message .= "  Storage: Could not determine (path may not exist)\n";
        }
        $message .= "  Uptime: {$result['uptime']}\n";
        $message .= "  Load Average: {$result['load_average']}";

        return $message;
    }

    /**
     * @return array{
     *     cpu_cores: int,
     *     ram_gb: int,
     *     storage_gb: int,
     *     ram_used_gb: int,
     *     storage_used_gb: int,
     *     cpu_used: int,
     *     uptime: string,
     *     load_average: float
     * }
     */
    private function collect(Node $node, SSHService $ssh): array
    {
        $timeout = self::TIMEOUT_SECONDS;

        $ssh->exec('echo "SSH connection OK"', $timeout);

        $uptime = $ssh->exec('uptime -p', $timeout);
        $freeOutput = $ssh->exec('free -b | grep Mem', $timeout);
        $diskPath = $node->type === 'directadmin'
            ? '/'
            : '/opt/talksasa/containers';
        $dfOutput = $ssh->exec("df {$diskPath} -B1 2>/dev/null | tail -1 || df / -B1 | tail -1", $timeout);
        $cpuOutput = $ssh->exec('grep -c ^processor /proc/cpuinfo', $timeout);
        $loadOutput = $ssh->exec('cat /proc/loadavg | awk \'{print $1, $2, $3}\'', $timeout);

        preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $freeOutput, $memMatches);
        $ramTotalBytes = (int) ($memMatches[1] ?? 0);
        $ramUsedBytes = (int) ($memMatches[2] ?? 0);
        $ramTotalGb = (int) ($ramTotalBytes / (1024 * 1024 * 1024));
        $ramUsedGb = (int) ($ramUsedBytes / (1024 * 1024 * 1024));

        $dfParts = preg_split('/\s+/', trim($dfOutput)) ?: [];
        $diskTotalBytes = (int) ($dfParts[1] ?? 0);
        $diskUsedBytes = (int) ($dfParts[2] ?? 0);
        $diskTotalGb = (int) ($diskTotalBytes / (1024 * 1024 * 1024));
        $diskUsedGb = (int) ($diskUsedBytes / (1024 * 1024 * 1024));

        $loads = array_map('floatval', explode(' ', trim($loadOutput)));
        $loadAverage = $loads[0] ?? 0.0;
        $cpuCores = (int) trim($cpuOutput);
        $cpuPercent = $cpuCores > 0 ? (int) (($loadAverage / $cpuCores) * 100) : 0;
        $cpuPercent = min(100, max(0, $cpuPercent));

        return [
            'cpu_cores' => $cpuCores,
            'ram_gb' => $ramTotalGb,
            'storage_gb' => $diskTotalGb,
            'ram_used_gb' => $ramUsedGb,
            'storage_used_gb' => $diskUsedGb,
            'cpu_used' => $cpuPercent,
            'uptime' => trim($uptime),
            'load_average' => $loadAverage,
        ];
    }

    /**
     * @param  array{
     *     cpu_cores: int,
     *     ram_gb: int,
     *     storage_gb: int,
     *     ram_used_gb: int,
     *     storage_used_gb: int,
     *     cpu_used: int,
     *     uptime: string,
     *     load_average: float
     * }  $metrics
     */
    private function persist(Node $node, array $metrics): void
    {
        $node->recordHeartbeat();

        $uptimePercent = str_contains($metrics['uptime'], 'minute') || str_contains($metrics['uptime'], 'hour')
            ? 99
            : 95;

        NodeMonitoring::create([
            'node_id' => $node->id,
            'uptime_percentage' => $uptimePercent,
            'ram_used_gb' => $metrics['ram_used_gb'],
            'ram_total_gb' => $metrics['ram_gb'],
            'storage_used_gb' => $metrics['storage_used_gb'],
            'storage_total_gb' => $metrics['storage_gb'],
            'cpu_percentage' => $metrics['cpu_used'],
            'recorded_at' => now(),
        ]);

        $healthy = $metrics['ram_used_gb'] <= ($metrics['ram_gb'] * 0.85)
            && ($metrics['storage_gb'] === 0 || $metrics['storage_used_gb'] <= ($metrics['storage_gb'] * 0.90));

        $node->update([
            'is_active' => true,
            'ram_gb' => $metrics['ram_gb'],
            'storage_gb' => $metrics['storage_gb'],
            'cpu_cores' => $metrics['cpu_cores'],
            'ram_used_gb' => $metrics['ram_used_gb'],
            'storage_used_gb' => $metrics['storage_used_gb'],
            'cpu_used' => $metrics['cpu_used'],
            'status' => $healthy ? 'online' : $node->status,
        ]);
    }

    /**
     * @return array{
     *     success: bool,
     *     reason: string,
     *     message: string,
     *     cpu_cores: int,
     *     ram_gb: int,
     *     storage_gb: int,
     *     ram_used_gb: int,
     *     storage_used_gb: int,
     *     cpu_used: int,
     *     uptime: string,
     *     load_average: float
     * }
     */
    private function failure(string $reason, string $message): array
    {
        return array_merge($this->emptyMetrics(), [
            'success' => false,
            'reason' => $reason,
            'message' => $message,
        ]);
    }

    /**
     * @return array{
     *     cpu_cores: int,
     *     ram_gb: int,
     *     storage_gb: int,
     *     ram_used_gb: int,
     *     storage_used_gb: int,
     *     cpu_used: int,
     *     uptime: string,
     *     load_average: float
     * }
     */
    private function emptyMetrics(): array
    {
        return [
            'cpu_cores' => 0,
            'ram_gb' => 0,
            'storage_gb' => 0,
            'ram_used_gb' => 0,
            'storage_used_gb' => 0,
            'cpu_used' => 0,
            'uptime' => '',
            'load_average' => 0.0,
        ];
    }
}
