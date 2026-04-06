<?php

namespace App\Http\Controllers\Customer;

use App\Models\Service;
use App\Models\ContainerMetric;
use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContainerController
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

            $containerService = new ContainerDeploymentService();
            $containerService->restart($service);

            return back()->with('success', 'Container restarted successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to restart container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to restart container']);
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

            $containerService = new ContainerDeploymentService();
            $containerService->suspend($service);

            return back()->with('success', 'Container stopped successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to stop container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to stop container']);
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

            $containerService = new ContainerDeploymentService();
            $containerService->unsuspend($service);

            return back()->with('success', 'Container started successfully');
        } catch (\Exception $e) {
            \Log::error("Failed to start container for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to start container']);
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
    public function metrics(Service $service): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json(['error' => 'Invalid service type'], 400);
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
}
