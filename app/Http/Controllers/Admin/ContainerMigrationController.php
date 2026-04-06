<?php

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use App\Models\Node;
use App\Services\Provisioning\ContainerMigrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContainerMigrationController
{
    /**
     * Show migration view for a service
     */
    public function index(Service $service): View
    {
        if ($service->product?->type !== 'container_hosting') {
            abort(404);
        }

        $deployment = $service->containerDeployment;
        if (!$deployment) {
            abort(404, 'Container not deployed');
        }

        $migrationService = new ContainerMigrationService();
        $availableTargets = $migrationService->getAvailableTargetNodes($deployment->node);

        return view('admin.container.migrate', compact('service', 'deployment', 'availableTargets'));
    }

    /**
     * Migrate a single service to a target node
     */
    public function migrate(Service $service, Request $request): RedirectResponse
    {
        try {
            if ($service->product?->type !== 'container_hosting') {
                return back()->withErrors(['error' => 'Service is not a container hosting service']);
            }

            $request->validate([
                'target_node_id' => 'required|exists:nodes,id',
                'reason' => 'nullable|string|max:255',
            ]);

            $targetNode = Node::findOrFail($request->target_node_id);
            $reason = $request->reason ?? 'manual';

            $migrationService = new ContainerMigrationService();
            $migrationService->migrate($service, $targetNode, $reason);

            return redirect()
                ->route('admin.services.show', $service)
                ->with('success', 'Container migrated successfully to ' . $targetNode->hostname);
        } catch (\Exception $e) {
            \Log::error("Migration failed for service {$service->id}: " . $e->getMessage());
            return back()->withErrors(['error' => 'Migration failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Migrate all containers from a node to another node
     */
    public function migrateNode(Node $node, Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'target_node_id' => 'required|exists:nodes,id',
                'reason' => 'nullable|string|max:255',
            ]);

            $targetNode = Node::findOrFail($request->target_node_id);
            $reason = $request->reason ?? 'manual';

            $migrationService = new ContainerMigrationService();
            $result = $migrationService->migrateNode($node, $targetNode, $reason);

            $migratedCount = count($result['migrated']);
            $failedCount = count($result['failed']);
            $message = "Migrated {$migratedCount} container(s) from {$node->hostname} to {$targetNode->hostname}";
            if ($failedCount > 0) {
                $message .= ". Failed to migrate {$failedCount} container(s).";
            }

            return redirect()
                ->route('admin.nodes.show', $node)
                ->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Node migration failed: " . $e->getMessage());
            return back()->withErrors(['error' => 'Migration failed: ' . $e->getMessage()]);
        }
    }
}
