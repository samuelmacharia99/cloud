<?php

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use App\Models\ContainerMetric;
use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerController
{
    /**
     * Restart a running container
     */
    public function restart(Service $service): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
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
     * Suspend a running container (change status to suspended)
     */
    public function suspend(Service $service): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $containerService = new ContainerDeploymentService();
            $containerService->suspend($service);

            return back()->with('success', 'Container suspended successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to suspend container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to suspend container: ' . $e->getMessage()]);
        }
    }

    /**
     * Stop a running container (alias for suspend)
     */
    public function stop(Service $service): RedirectResponse
    {
        return $this->suspend($service);
    }

    /**
     * Start a stopped container
     */
    public function start(Service $service): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
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
        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Service is not a container hosting service'], 400);
            }

            $containerService = new ContainerDeploymentService();
            $logs = $containerService->getLogs($service, 100);

            return response()->json(['logs' => $logs]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch logs for service {$service->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch logs: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Redeploy a container (terminate and recreate)
     */
    public function redeploy(Service $service): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $containerService = new ContainerDeploymentService();

            // Terminate existing deployment
            $containerService->terminate($service);

            // Clear node assignment to trigger fresh node selection
            $service->update(['node_id' => null]);

            // Re-provision
            $containerService->deploy($service);

            return back()->with('success', 'Container redeployed successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to redeploy container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to redeploy container: ' . $e->getMessage()]);
        }
    }

    /**
     * Get container metrics for chart display
     */
    public function metrics(Service $service): JsonResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Service is not a container hosting service'], 400);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return response()->json(['labels' => [], 'cpu' => [], 'memory' => []]);
            }

            // Get last 24 hours of metrics
            $metrics = ContainerMetric::where('container_deployment_id', $deployment->id)
                ->where('recorded_at', '>=', now()->subHours(24))
                ->orderBy('recorded_at')
                ->get();

            $labels = $metrics->map(fn($m) => $m->recorded_at->format('H:i'))->toArray();
            $cpuData = $metrics->map(fn($m) => round($m->cpu_percentage, 2))->toArray();
            $memoryData = $metrics->map(fn($m) => $m->memory_used_mb)->toArray();

            return response()->json([
                'labels' => $labels,
                'cpu' => $cpuData,
                'memory' => $memoryData,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to fetch metrics for service {$service->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch metrics'], 500);
        }
    }

    /**
     * Bind a domain to a container
     */
    public function bindDomain(Service $service, Request $request): RedirectResponse
    {
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

            // Create domain record
            $domain = ContainerDomain::create([
                'container_deployment_id' => $deployment->id,
                'domain' => strtolower($request->domain),
                'status' => 'pending',
            ]);

            // Bind domain to nginx
            $nginxService = new NginxProxyService();
            $nginxService->bind($domain);

            return back()->with('success', "Domain {$domain->domain} bound successfully");
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
        try {
            $backupService = new \App\Services\Provisioning\ContainerBackupService();
            $backupService->deleteBackup($backup);

            return back()->with('success', "Backup '{$backup->backup_name}' deleted.");
        } catch (\Exception $e) {
            \Log::error("Failed to delete backup {$backup->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Delete failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Show container edit form
     */
    public function edit(Service $service)
    {
        if ($service->product?->type !== 'container_hosting') {
            return back()->withErrors(['error' => 'Service is not a container hosting service']);
        }

        $deployment = $service->containerDeployment;
        if (!$deployment) {
            return back()->withErrors(['error' => 'Container not deployed yet']);
        }

        return view('admin.services.containers.edit', [
            'service' => $service,
            'deployment' => $deployment,
            'statuses' => ['pending', 'provisioning', 'running', 'stopped', 'failed', 'terminated'],
        ]);
    }

    /**
     * Update container deployment details
     */
    public function update(Service $service, Request $request): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            $validated = $request->validate([
                'status' => 'required|in:pending,provisioning,running,stopped,failed,terminated',
            ]);

            $oldStatus = $deployment->status;
            $deployment->update($validated);

            \Log::info('Container deployment status updated', [
                'service_id' => $service->id,
                'deployment_id' => $deployment->id,
                'old_status' => $oldStatus,
                'new_status' => $deployment->status,
                'updated_by' => auth()->id(),
            ]);

            return back()->with('success', "Container status updated from {$oldStatus} to {$validated['status']}");
        } catch (\Exception $e) {
            \Log::error("Failed to update container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to update container: ' . $e->getMessage()]);
        }
    }

    /**
     * Provision a pending container
     */
    public function provision(Service $service): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return back()->withErrors(['error' => 'Container not deployed yet']);
            }

            if ($deployment->status !== 'pending') {
                return back()->withErrors(['error' => "Container must be in pending status to provision. Current status: {$deployment->status}"]);
            }

            // Provision the container
            $containerService = new ContainerDeploymentService();
            $containerService->deploy($service);

            \Log::info('Container provisioned via admin action', [
                'service_id' => $service->id,
                'deployment_id' => $deployment->id,
                'provisioned_by' => auth()->id(),
            ]);

            return back()->with('success', 'Container provisioning started successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to provision container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to provision container: ' . $e->getMessage()]);
        }
    }
}
