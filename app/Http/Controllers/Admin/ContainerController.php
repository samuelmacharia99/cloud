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
     * Stop a running container
     */
    public function stop(Service $service): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
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
}
