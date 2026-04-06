<?php

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use App\Models\ContainerMetric;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;

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
}
