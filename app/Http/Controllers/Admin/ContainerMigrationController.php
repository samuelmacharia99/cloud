<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MigrateContainerServiceRequest;
use App\Jobs\MigrateContainerServiceJob;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerMigrationProgress;
use App\Services\Provisioning\ContainerMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContainerMigrationController
{
    public function __construct(
        private readonly ContainerMigrationService $migrationService,
        private readonly ContainerMigrationProgress $progress,
    ) {}

    /**
     * Show migration view for a service
     */
    public function index(Service $service): View
    {
        if ($service->product?->type !== 'container_hosting') {
            abort(404);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment) {
            abort(404, 'Container not deployed');
        }

        $availableTargets = $this->migrationService->getAvailableTargetNodes($deployment->node);

        return view('admin.container.migrate', [
            'service' => $service,
            'deployment' => $deployment,
            'availableTargets' => $availableTargets,
            'migrationProgress' => $this->progress->operatorView($service),
        ]);
    }

    /**
     * Queue a migration and hand the operator over to the live console.
     */
    public function migrate(Service $service, MigrateContainerServiceRequest $request): RedirectResponse
    {
        if ($service->product?->type !== 'container_hosting') {
            return back()->withErrors(['error' => 'Service is not a application hosting service']);
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->node) {
            return back()->withErrors(['error' => 'This service has no deployment to migrate.']);
        }

        if ($this->progress->isActive($service)) {
            return back()->withErrors(['error' => 'A migration is already running for this service. Watch the console below.']);
        }

        $targetNode = $request->targetNode();
        $reason = $request->validated('reason') ?? 'manual';

        // Mark the console busy before dispatching so a double submit cannot queue twice.
        $this->progress->queue($service, $deployment->node, $targetNode, $reason);

        MigrateContainerServiceJob::dispatch($service->id, $targetNode->id, $reason);

        return redirect()
            ->route('admin.services.container.migrate', $service)
            ->with('success', 'Migration queued for '.$targetNode->hostname.'. Progress streams in the console below.');
    }

    /**
     * Live migration state for the console.
     */
    public function progress(Service $service): JsonResponse
    {
        if ($service->product?->type !== 'container_hosting') {
            abort(404);
        }

        return response()->json($this->progress->operatorView($service));
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

            $result = $this->migrationService->migrateNode($node, $targetNode, $reason);

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
            \Log::error('Node migration failed: '.$e->getMessage());

            return back()->withErrors(['error' => 'Migration failed: '.$e->getMessage()]);
        }
    }
}
