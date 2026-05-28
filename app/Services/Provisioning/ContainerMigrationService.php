<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Models\Node;
use App\Services\SSH\SSHService;
use Illuminate\Database\Eloquent\Collection;
use Exception;

class ContainerMigrationService
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';
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

        $oldServiceStatus = $service->status;
        $oldNodeId = $oldDeployment->node_id;
        $oldContainerName = $oldDeployment->container_name;
        $oldDeploymentStatus = $oldDeployment->status;

        try {
            // Phase 1: Deploy on target first (no destructive action on source yet).
            $service->update(['status' => 'provisioning', 'node_id' => $targetNode->id]);
            $oldDeployment->update(['status' => 'deploying']);

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

            // Phase 2: Best-effort cleanup on old node after successful target deployment.
            $this->cleanupOldDeployment($oldContainerName, $oldDeployment->node);

            \Log::info("Container migrated for service {$service->id} from node {$oldDeployment->node_id} to {$targetNode->id}");
        } catch (Exception $e) {
            // Roll back service reference to old node.
            $service->update([
                'node_id' => $oldNodeId,
                'status' => $oldServiceStatus,
            ]);

            // Restore deployment metadata best-effort.
            $oldDeployment->update([
                'node_id' => $oldNodeId,
                'status' => $oldDeploymentStatus,
            ]);

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

    /**
     * Clean up old deployment artifacts on source node after successful migration.
     */
    private function cleanupOldDeployment(string $containerName, ?Node $sourceNode): void
    {
        if (!$sourceNode) {
            return;
        }

        try {
            $ssh = SSHService::forNode($sourceNode);
            $containerPath = self::CONTAINER_BASE_PATH . '/' . $containerName;

            try {
                @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down -v", 120);
                @$ssh->deleteDir($containerPath);
            } finally {
                $ssh->disconnect();
            }
        } catch (Exception $e) {
            // Cleanup failures should not fail the migration after successful cutover.
            \Log::warning("Post-migration cleanup failed for {$containerName}", [
                'node_id' => $sourceNode->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
