<?php

namespace App\Http\Controllers\Customer;

use App\Models\Service;
use App\Models\ContainerMetric;
use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerFileService;
use App\Services\Provisioning\NginxProxyService;
use App\Services\SSH\SSHService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContainerController extends Controller
{
    /**
     * Show container dashboard
     */
    public function show(Service $service): View
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($service->product?->type !== 'container_hosting') {
            abort(404);
        }

        $service->load('containerDeployment', 'product.containerTemplate');

        $deployment = $service->containerDeployment;
        $status = null;

        if ($deployment) {
            $containerService = new ContainerDeploymentService();
            try {
                $status = $containerService->getStatus($service);
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch status for service {$service->id}");
            }
        }

        return view('customer.services.container', compact('service', 'deployment', 'status'));
    }

    /**
     * Restart container
     */
    public function restart(Service $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Pre-flight check: validate node has SSH credentials
            if (!$deployment->node->ssh_username || (!$deployment->node->ssh_password && !$deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $containerService = new ContainerDeploymentService();
            $containerService->restart($service);

            return back()->with('success', 'Container restarted successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to restart container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to restart container: ' . $e->getMessage()]);
        }
    }

    /**
     * Stop container
     */
    public function stop(Service $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Pre-flight check: validate node has SSH credentials
            if (!$deployment->node->ssh_username || (!$deployment->node->ssh_password && !$deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $containerService = new ContainerDeploymentService();
            $containerService->suspend($service);

            return back()->with('success', 'Container stopped successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to stop container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to stop container: ' . $e->getMessage()]);
        }
    }

    /**
     * Start container
     */
    public function start(Service $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Invalid service type']);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Pre-flight check: validate node has SSH credentials
            if (!$deployment->node->ssh_username || (!$deployment->node->ssh_password && !$deployment->node->da_login_key)) {
                return back()->withErrors(['error' => 'Container host is not properly configured (missing SSH credentials). Please contact support.']);
            }

            $containerService = new ContainerDeploymentService();
            $containerService->unsuspend($service);

            return back()->with('success', 'Container started successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to start container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to start container: ' . $e->getMessage()]);
        }
    }

    /**
     * Get container logs
     */
    public function logs(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
            }

            $containerService = new ContainerDeploymentService();
            $logs = $containerService->getLogs($service, 100);

            return response()->json(['logs' => $logs]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch logs for service {$service->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch logs'], 500);
        }
    }

    /**
     * Get container metrics for chart display
     */
    public function metrics(Service $service, Request $request): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return response()->json([
                    'labels' => [],
                    'cpu' => [],
                    'memory' => [],
                    'net_rx' => [],
                    'net_tx' => [],
                    'disk_read' => [],
                    'disk_write' => [],
                    'summary' => null,
                ]);
            }

            // Parse hours parameter (1, 6, 24, 168 for 7 days)
            $hours = (int) $request->query('hours', 24);
            $validHours = [1, 6, 24, 168];
            if (!in_array($hours, $validHours)) {
                $hours = 24;
            }

            // Fetch metrics for the requested period
            $metrics = ContainerMetric::where('container_deployment_id', $deployment->id)
                ->where('recorded_at', '>=', now()->subHours($hours))
                ->orderBy('recorded_at')
                ->get();

            $labels = $metrics->map(fn($m) => $m->recorded_at->format('H:i'))->toArray();
            $cpuData = $metrics->map(fn($m) => round($m->cpu_percentage, 2))->toArray();
            $memoryData = $metrics->map(fn($m) => $m->memory_used_mb)->toArray();
            $netRxData = $metrics->map(fn($m) => $m->net_io_rx_bytes ?? 0)->toArray();
            $netTxData = $metrics->map(fn($m) => $m->net_io_tx_bytes ?? 0)->toArray();
            $diskReadData = $metrics->map(fn($m) => $m->block_io_read_bytes ?? 0)->toArray();
            $diskWriteData = $metrics->map(fn($m) => $m->block_io_write_bytes ?? 0)->toArray();

            // Calculate summary stats
            $summary = null;
            if ($metrics->count() > 0) {
                $summary = [
                    'cpu_avg' => round($metrics->avg('cpu_percentage'), 2),
                    'cpu_peak' => round($metrics->max('cpu_percentage'), 2),
                    'memory_avg' => round($metrics->avg('memory_used_mb'), 0),
                    'memory_peak' => (int) $metrics->max('memory_used_mb'),
                    'memory_limit_mb' => $metrics->first()?->memory_limit_mb ?? 0,
                    'net_rx_total' => $metrics->sum('net_io_rx_bytes'),
                    'net_tx_total' => $metrics->sum('net_io_tx_bytes'),
                    'uptime_seconds' => $deployment->getUptimeSeconds(),
                    'uptime_human' => $this->formatUptime($deployment->getUptimeSeconds()),
                ];
            }

            return response()->json([
                'labels' => $labels,
                'cpu' => $cpuData,
                'memory' => $memoryData,
                'net_rx' => $netRxData,
                'net_tx' => $netTxData,
                'disk_read' => $diskReadData,
                'disk_write' => $diskWriteData,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch metrics for service {$service->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch metrics'], 500);
        }
    }

    /**
     * Get storage usage stats for the container
     */
    public function storageStats(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return response()->json(['error' => 'Container not deployed yet'], 400);
            }

            $ssh = SSHService::forNode($deployment->node);
            $fileService = new ContainerFileService($ssh);

            $stats = $fileService->getStorageUsage($deployment);

            return response()->json([
                'used_bytes' => $stats['used_bytes'],
                'human' => $stats['human'],
                'container_name' => $deployment->container_name,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch storage stats for service {$service->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch storage stats'], 500);
        }
    }

    /**
     * Format uptime in human-readable format
     */
    private function formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes}m";
        }
    }

    /**
     * Get comprehensive health and status data for the container
     */
    public function health(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($service->product?->type !== 'container_hosting') {
            return response()->json(['error' => 'Invalid service type'], 400);
        }

        $deployment = $service->containerDeployment;
        if (!$deployment) {
            return response()->json(['error' => 'Not deployed'], 400);
        }

        $deployment->load('node', 'domains', 'migratedFromNode');

        // Calculate health score
        $score = 100;
        if ($deployment->status !== 'running') $score -= 50;
        $score -= min(30, $deployment->restart_attempts * 5);
        if (!$deployment->last_status_check_at || $deployment->last_status_check_at->lt(now()->subHour())) $score -= 5;
        if ($deployment->last_restart_at && $deployment->last_restart_at->gt(now()->subHour())) $score -= 5;
        $score = max(0, $score);

        // Determine incident level
        $incidentLevel = 'none';
        $incidentMessage = null;
        if ($deployment->status === 'failed') {
            $incidentLevel = 'critical';
            $incidentMessage = 'Container has failed. Check logs for details.';
        } elseif ($deployment->restart_attempts > 5) {
            $incidentLevel = 'warning';
            $incidentMessage = "{$deployment->restart_attempts} restarts detected. Monitor for instability.";
        } elseif ($deployment->last_restart_at && $deployment->last_restart_at->gt(now()->subHour())) {
            $incidentLevel = 'warning';
            $incidentMessage = 'Container restarted recently (within the last hour).';
        }

        // Calculate bandwidth analytics
        $bwQuery = fn(int $hours) => ContainerMetric::where('container_deployment_id', $deployment->id)
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->selectRaw('SUM(net_io_rx_bytes) as rx, SUM(net_io_tx_bytes) as tx, MAX(net_io_rx_bytes + net_io_tx_bytes) as peak')
            ->first();

        $bw1h  = $bwQuery(1);
        $bw24h = $bwQuery(24);
        $bw7d  = $bwQuery(168);

        // Calculate network activity rate (bytes/min from last 5 metrics)
        $recentMetrics = ContainerMetric::where('container_deployment_id', $deployment->id)
            ->orderByDesc('recorded_at')->limit(5)->get();
        $activityRate = 0;
        if ($recentMetrics->count() >= 2) {
            $first = $recentMetrics->first();
            $last = $recentMetrics->last();
            $bytesTotal = ($first->net_io_rx_bytes + $first->net_io_tx_bytes) -
                         ($last->net_io_rx_bytes + $last->net_io_tx_bytes);
            $minutesElapsed = max(1, $first->recorded_at->diffInMinutes($last->recorded_at) ?: 1);
            $activityRate = max(0, $bytesTotal / $minutesElapsed);
        }

        // Build deployment timeline
        $timeline = [];
        if ($deployment->deployed_at) {
            $timeline[] = [
                'type' => 'deployed',
                'label' => 'Deployed',
                'at' => $deployment->deployed_at->toIso8601String(),
                'human' => $deployment->deployed_at->diffForHumans(),
            ];
        }
        if ($deployment->migrated_at && $deployment->migrated_from_node_id) {
            $fromHost = $deployment->migratedFromNode?->hostname ?? 'unknown node';
            $timeline[] = [
                'type' => 'migrated',
                'label' => "Migrated from {$fromHost}",
                'at' => $deployment->migrated_at->toIso8601String(),
                'human' => $deployment->migrated_at->diffForHumans(),
            ];
        }
        if ($deployment->last_restart_at) {
            $timeline[] = [
                'type' => 'restart',
                'label' => "Restarted ({$deployment->restart_attempts} total)",
                'at' => $deployment->last_restart_at->toIso8601String(),
                'human' => $deployment->last_restart_at->diffForHumans(),
            ];
        }
        usort($timeline, fn($a, $b) => $b['at'] <=> $a['at']);

        // Template allocation
        $template = $service->product->containerTemplate;

        return response()->json([
            'status'              => $deployment->status,
            'health_score'        => $score,
            'incident_level'      => $incidentLevel,
            'incident_message'    => $incidentMessage,
            'restart_attempts'    => $deployment->restart_attempts,
            'last_restart_at'     => $deployment->last_restart_at?->toIso8601String(),
            'last_restart_human'  => $deployment->last_restart_at?->diffForHumans(),
            'uptime_seconds'      => $deployment->getUptimeSeconds(),
            'uptime_human'        => $this->formatUptime($deployment->getUptimeSeconds()),
            'deployed_at_ts'      => $deployment->deployed_at?->timestamp,
            'last_check_human'    => $deployment->last_status_check_at?->diffForHumans() ?? 'Never',
            'node' => $deployment->node ? [
                'hostname'   => $deployment->node->hostname,
                'region'     => $deployment->node->region ?? 'N/A',
                'datacenter' => $deployment->node->datacenter ?? 'N/A',
                'ip'         => $deployment->node->ip_address,
                'status'     => $deployment->node->status,
            ] : null,
            'ssl_domains' => $deployment->domains->map(fn($d) => [
                'domain'      => $d->domain,
                'ssl_enabled' => $d->ssl_enabled,
                'status'      => $d->status,
                'verified_at' => $d->verified_at?->format('Y-m-d'),
            ])->values(),
            'bandwidth' => [
                '1h'  => ['rx' => (int)($bw1h->rx ?? 0),  'tx' => (int)($bw1h->tx ?? 0),  'peak' => (int)($bw1h->peak ?? 0)],
                '24h' => ['rx' => (int)($bw24h->rx ?? 0), 'tx' => (int)($bw24h->tx ?? 0), 'peak' => (int)($bw24h->peak ?? 0)],
                '7d'  => ['rx' => (int)($bw7d->rx ?? 0),  'tx' => (int)($bw7d->tx ?? 0),  'peak' => (int)($bw7d->peak ?? 0)],
            ],
            'activity_rate_bytes_per_min' => (int)$activityRate,
            'allocation' => [
                'cpu_cores'  => $deployment->cpu_limit  ?? $template?->required_cpu_cores,
                'memory_mb'  => $deployment->memory_limit_mb ?? $template?->required_ram_mb,
                'storage_gb' => $template?->required_storage_gb,
            ],
            'timeline'       => $timeline,
            'restart_policy' => $deployment->restart_policy,
            'auto_restart'   => $deployment->auto_restart,
            'selected_version' => $deployment->selected_version,
            'container_name' => $deployment->container_name,
            'assigned_port'  => $deployment->assigned_port,
        ]);
    }

    /**
     * Bind a domain to a container
     */
    public function bindDomain(Service $service, Request $request): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $request->validate([
                'domain' => 'required|string|regex:/^([a-z0-9]([-a-z0-9]*[a-z0-9])?\.)+[a-z]{2,}$/i|unique:container_domains,domain',
            ]);

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            // Check DNS configuration
            $nginxService = new NginxProxyService();
            $dnsCorrect = $nginxService->checkDns($request->domain, $deployment->node->ip_address);

            // Create domain record
            $domain = ContainerDomain::create([
                'container_deployment_id' => $deployment->id,
                'domain' => strtolower($request->domain),
                'status' => 'pending',
            ]);

            // Bind domain to nginx
            $nginxService->bind($domain);

            $message = "Domain {$domain->domain} bound successfully";
            if (!$dnsCorrect) {
                $message .= " (Note: DNS is not yet pointing to {$deployment->node->ip_address})";
            }

            return back()->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Failed to bind domain for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to bind domain: ' . $e->getMessage()]);
        }
    }

    /**
     * Unbind a domain from a container
     */
    public function unbindDomain(Service $service, ContainerDomain $domain): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            if ($domain->deployment_id !== $service->containerDeployment?->id) {
                return back()->withErrors(['error' => 'Domain does not belong to this service']);
            }

            $domainName = $domain->domain;

            $nginxService = new NginxProxyService();
            $nginxService->unbind($domain);

            return back()->with('success', "Domain {$domainName} unbind successfully");
        } catch (\Exception $e) {
            \Log::error("Failed to unbind domain for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to unbind domain: ' . $e->getMessage()]);
        }
    }

    /**
     * Enable SSL for a domain
     */
    public function enableSsl(Service $service, ContainerDomain $domain): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            if ($domain->deployment_id !== $service->containerDeployment?->id) {
                return back()->withErrors(['error' => 'Domain does not belong to this service']);
            }

            if ($domain->status !== 'active') {
                return back()->withErrors(['error' => 'Domain must be active to enable SSL']);
            }

            $nginxService = new NginxProxyService();
            $nginxService->enableSsl($domain);

            return back()->with('success', "SSL enabled for {$domain->domain}");
        } catch (\Exception $e) {
            \Log::error("Failed to enable SSL for domain {$domain->domain}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to enable SSL: ' . $e->getMessage()]);
        }
    }

    public function createBackup(Service $service)
    {
        $this->authorize('view', $service);

        try {
            $backupService = new \App\Services\Provisioning\ContainerBackupService();
            $backup = $backupService->createBackup($service, 'manual');

            return back()->with('success', "Backup '{$backup->backup_name}' created successfully.");
        } catch (\Exception $e) {
            \Log::error("Failed to create backup for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Backup failed: ' . $e->getMessage()]);
        }
    }

    public function restoreBackup(Service $service, \App\Models\ContainerBackup $backup)
    {
        $this->authorize('view', $service);

        try {
            $backupService = new \App\Services\Provisioning\ContainerBackupService();
            $backupService->restoreBackup($backup);

            return back()->with('success', "Container restored from backup '{$backup->backup_name}'.");
        } catch (\Exception $e) {
            \Log::error("Failed to restore backup {$backup->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Restore failed: ' . $e->getMessage()]);
        }
    }

    public function deleteBackup(Service $service, \App\Models\ContainerBackup $backup)
    {
        $this->authorize('view', $service);

        try {
            $backupService = new \App\Services\Provisioning\ContainerBackupService();
            $backupService->deleteBackup($backup);

            return back()->with('success', "Backup '{$backup->backup_name}' deleted.");
        } catch (\Exception $e) {
            \Log::error("Failed to delete backup {$backup->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Delete failed: ' . $e->getMessage()]);
        }
    }
}
