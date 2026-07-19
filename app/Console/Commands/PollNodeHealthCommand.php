<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\NodeMonitoring;
use App\Services\SSH\SSHService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollNodeHealthCommand extends BaseCronCommand
{
    protected $signature = 'cron:poll-node-health';
    protected $description = 'Actively poll node health via SSH (alternative to heartbeats)';

    protected function handleCron(): string
    {
        $lines = [];
        $nodes = Node::where('is_active', true)
            ->whereIn('type', ['container_host', 'database_server', 'directadmin'])
            ->get();

        $healthy = 0;
        $degraded = 0;
        $failed = 0;

        foreach ($nodes as $node) {
            try {
                // Skip if SSH credentials not configured (SSH password or key required)
                if (!$node->ssh_username || !$node->ssh_password) {
                    Log::warning("NODE POLL SKIPPED: {$node->name} - SSH credentials not configured");
                    continue;
                }

                // Create SSH connection
                $ssh = SSHService::forNode($node);

                // 5s was too aggressive for channel open under load and surfaced as
                // phpseclib "Undefined array key 1" (CHANNEL_EXEC) wrapped as SSH failures.
                $timeout = 20;

                // Test connectivity
                $ssh->exec('echo "OK"', $timeout);

                // Collect system metrics
                $uptime = $ssh->exec('uptime -p', $timeout);
                $freeOutput = $ssh->exec('free -b | grep Mem', $timeout);
                $dfOutput = $ssh->exec('df /opt/talksasa/containers -B1 2>/dev/null | tail -1 || df / -B1 | tail -1', $timeout);
                $cpuOutput = $ssh->exec('grep -c ^processor /proc/cpuinfo', $timeout);
                $loadOutput = $ssh->exec('cat /proc/loadavg | awk \'{print $1, $2, $3}\'', $timeout);

                // Parse memory
                preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $freeOutput, $memMatches);
                $ramTotalBytes = $memMatches[1] ?? 0;
                $ramUsedBytes = $memMatches[2] ?? 0;
                $ramTotalGb = intval($ramTotalBytes / (1024 * 1024 * 1024));
                $ramUsedGb = intval($ramUsedBytes / (1024 * 1024 * 1024));

                // Parse disk
                $dfParts = preg_split('/\s+/', trim($dfOutput));
                $diskTotalBytes = intval($dfParts[1] ?? 0);
                $diskUsedBytes = intval($dfParts[2] ?? 0);
                $diskTotalGb = intval($diskTotalBytes / (1024 * 1024 * 1024));
                $diskUsedGb = intval($diskUsedBytes / (1024 * 1024 * 1024));

                // Parse CPU
                $loads = array_map('floatval', explode(' ', trim($loadOutput)));
                $loadAverage = $loads[0] ?? 0;
                $cpuCores = intval(trim($cpuOutput));
                $cpuPercent = $cpuCores > 0 ? intval(($loadAverage / $cpuCores) * 100) : 0;
                $cpuPercent = min(100, max(0, $cpuPercent));

                // Estimate uptime percentage
                $uptimePercent = strpos($uptime, 'minute') !== false || strpos($uptime, 'hour') !== false ? 99 : 95;

                // Record monitoring data
                NodeMonitoring::create([
                    'node_id' => $node->id,
                    'uptime_percentage' => $uptimePercent,
                    'ram_used_gb' => $ramUsedGb,
                    'ram_total_gb' => $ramTotalGb,
                    'storage_used_gb' => $diskUsedGb,
                    'storage_total_gb' => $diskTotalGb,
                    'cpu_percentage' => $cpuPercent,
                    'recorded_at' => now(),
                ]);

                // Update node with latest metrics
                $node->update([
                    'last_heartbeat_at' => now(),
                    'ram_gb' => $ramTotalGb,
                    'storage_gb' => $diskTotalGb,
                    'cpu_cores' => $cpuCores,
                    'ram_used_gb' => $ramUsedGb,
                    'storage_used_gb' => $diskUsedGb,
                    'cpu_used' => $cpuPercent,
                ]);

                // Determine status based on resource thresholds
                $ramPercent = $ramTotalGb > 0 ? intval($ramUsedGb / $ramTotalGb * 100) : 0;
                $storagePercent = $diskTotalGb > 0 ? intval($diskUsedGb / $diskTotalGb * 100) : 0;

                if ($ramPercent > 85 || $storagePercent > 90 || $uptimePercent < 95) {
                    $node->update(['status' => 'degraded']);
                    $degraded++;
                    Log::warning("NODE DEGRADED: {$node->name} - RAM: {$ramPercent}%, Storage: {$storagePercent}%, Uptime: {$uptimePercent}%");
                } else {
                    $node->update(['status' => 'online']);
                    $healthy++;
                    Log::info("NODE HEALTHY: {$node->name} - RAM: {$ramPercent}%, Storage: {$storagePercent}%, CPU: {$cpuPercent}%");
                }

                $ssh->disconnect();

            } catch (\Exception $e) {
                $failed++;
                Log::error("NODE POLL FAILED: {$node->name} ({$node->ip_address}) - {$e->getMessage()}");

                // Transient SSH/channel races: keep heartbeat-fresh nodes degraded, not offline,
                // so admin UI doesn't flap while the node agent is still reporting in.
                $message = strtolower($e->getMessage());
                $transient = str_contains($message, 'undefined array key')
                    || str_contains($message, 'exec returned false')
                    || str_contains($message, 'timed out')
                    || str_contains($message, 'channel');

                $heartbeatFresh = $node->last_heartbeat_at
                    && $node->last_heartbeat_at->greaterThan(now()->subMinutes(5));

                $node->update([
                    'status' => ($transient && $heartbeatFresh) ? 'degraded' : 'offline',
                ]);
            }
        }

        $lines[] = "Polled {$nodes->count()} node(s).";
        if ($healthy) {
            $lines[] = "{$healthy} healthy.";
        }
        if ($degraded) {
            $lines[] = "{$degraded} degraded.";
        }
        if ($failed) {
            $lines[] = "{$failed} failed/offline.";
        }

        return implode(' ', $lines);
    }
}
