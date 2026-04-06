<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;
use Exception;

class ContainerMigrationService
{
    protected ContainerDeploymentService $deploymentService;

    public function __construct()
    {
        $this->deploymentService = new ContainerDeploymentService();
    }

    /**
     * Migrate a container service to a different node
     */
    public function migrate(Service $service, Node $targetNode, string $reason = 'manual'): void
    {
        // Validate
        if ($targetNode->type !== 'container_host') {
            throw new Exception('Target node is not a container host');
        }

        if (!$targetNode->is_active) {
            throw new Exception('Target node is not active');
        }

        $service->load(['containerDeployment.node', 'product.containerTemplate', 'user']);

        $oldDeployment = $service->containerDeployment;
        if (!$oldDeployment) {
            throw new Exception('Service has no active deployment');
        }

        if ($oldDeployment->node_id === $targetNode->id) {
            throw new Exception('Container is already on the target node');
        }

        try {
            // Mark service and deployment as in-progress
            $service->update(['status' => 'provisioning']);
            $oldDeployment->update(['status' => 'terminating']);

            // Terminate on source node
            $this->deploymentService->terminate($service);

            // Mark old deployment as terminated
            $oldDeployment->update([
                'status' => 'terminated',
                'terminated_at' => now(),
            ]);

            // Update service to point to target node
            $service->update(['node_id' => $targetNode->id]);

            // Deploy on target node
            $this->deploymentService->deploy($service);

            // Get new deployment and add migration metadata
            $newDeployment = $service->fresh()->containerDeployment;
            if ($newDeployment) {
                $newDeployment->update([
                    'migrated_from_node_id' => $oldDeployment->node_id,
                    'migrated_at' => now(),
                    'migration_reason' => $reason,
                ]);
            }

            \Log::info("Container migrated for service {$service->id} from node {$oldDeployment->node_id} to {$targetNode->id}");
        } catch (Exception $e) {
            $service->update(['status' => 'failed']);
            \Log::error("Container migration failed for service {$service->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Migrate all containers from a source node to a target node
     */
    public function migrateNode(Node $sourceNode, Node $targetNode, string $reason = 'manual'): array
    {
        if ($sourceNode->id === $targetNode->id) {
            throw new Exception('Source and target nodes must be different');
        }

        // Get all services with active deployments on source node
        $services = Service::whereHas('containerDeployment', function ($query) use ($sourceNode) {
            $query->where('node_id', $sourceNode->id)
                  ->whereIn('status', ['running', 'stopped', 'deploying']);
        })->get();

        $migrated = [];
        $failed = [];

        foreach ($services as $service) {
            try {
                $this->migrate($service, $targetNode, $reason);
                $migrated[] = $service->id;
            } catch (Exception $e) {
                \Log::error("Failed to migrate service {$service->id}: " . $e->getMessage());
                $failed[] = $service->id;
            }
        }

        return [
            'migrated' => $migrated,
            'failed' => $failed,
        ];
    }

    /**
     * Get available target nodes for migration
     */
    public function getAvailableTargetNodes(Node $currentNode): Collection
    {
        return Node::where('type', 'container_host')
            ->where('is_active', true)
            ->where('id', '!=', $currentNode->id)
            ->orderBy('status', 'asc')
            ->orderByRaw('(SELECT COUNT(*) FROM container_deployments WHERE node_id = nodes.id AND status IN ("running", "stopped")) ASC')
            ->get();
    }
}
