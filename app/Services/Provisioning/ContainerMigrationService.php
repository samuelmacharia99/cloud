<?php

namespace App\Services\Provisioning;

use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ContainerMigrationService
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private const MIGRATE_BASE_PATH = '/opt/talksasa/migrations';

    private const TRANSFER_TIMEOUT = 1800;

    protected ContainerDeploymentService $deploymentService;

    public function __construct()
    {
        $this->deploymentService = new ContainerDeploymentService;
    }

    /**
     * Migrate a container service to a different node, preserving volumes/app data.
     */
    public function migrate(Service $service, Node $targetNode, string $reason = 'manual'): void
    {
        if ($targetNode->type !== 'container_host') {
            throw new Exception('Target node is not a container host');
        }

        if (! $targetNode->is_active) {
            throw new Exception('Target node is not active');
        }

        $service->load(['containerDeployment.node', 'product.containerTemplate', 'user']);

        $oldDeployment = $service->containerDeployment;
        if (! $oldDeployment) {
            throw new Exception('Service has no active deployment');
        }

        $sourceNode = $oldDeployment->node;
        if (! $sourceNode) {
            throw new Exception('Source node is missing');
        }

        if ($oldDeployment->node_id === $targetNode->id) {
            throw new Exception('Container is already on the target node');
        }

        $oldServiceStatus = $service->status;
        $oldNodeId = $oldDeployment->node_id;
        $oldContainerName = $oldDeployment->container_name;
        $oldDeploymentStatus = $oldDeployment->status;
        $archiveName = 'migrate-'.$service->id.'-'.now()->format('YmdHis').'.tar.gz';
        $remoteArchive = self::MIGRATE_BASE_PATH.'/'.$archiveName;
        $localArchive = storage_path('app/migrations/'.$archiveName);

        try {
            $service->update(['status' => 'provisioning']);
            $oldDeployment->update(['status' => 'deploying']);

            $this->packContainerOnNode($sourceNode, $oldContainerName, $remoteArchive);
            $this->transferArchive($sourceNode, $targetNode, $remoteArchive, $localArchive);
            $this->unpackContainerOnNode($targetNode, $oldContainerName, $remoteArchive);

            $service->update(['node_id' => $targetNode->id]);
            $oldDeployment->update([
                'node_id' => $targetNode->id,
                'migrated_from_node_id' => $oldNodeId,
                'migrated_at' => now(),
                'migration_reason' => $reason,
                'status' => 'running',
            ]);

            $freshDeployment = $service->fresh()->containerDeployment;
            $targetSsh = SSHService::forNode($targetNode);
            try {
                $this->deploymentService->ensureComposeFileExists($targetSsh, $freshDeployment);
                $this->deploymentService->startComposeStack($targetSsh, $service->fresh(), $freshDeployment);
            } finally {
                $targetSsh->disconnect();
            }

            $service->update(['status' => $oldServiceStatus]);

            $this->cleanupOldDeployment($oldContainerName, $sourceNode);
            $this->cleanupRemoteArchive($sourceNode, $remoteArchive);
            $this->cleanupRemoteArchive($targetNode, $remoteArchive);

            Log::info("Container data-migrated for service {$service->id} from node {$oldNodeId} to {$targetNode->id}");
        } catch (Exception $e) {
            $service->update([
                'node_id' => $oldNodeId,
                'status' => $oldServiceStatus,
            ]);

            $oldDeployment->update([
                'node_id' => $oldNodeId,
                'status' => $oldDeploymentStatus,
            ]);

            @$this->cleanupRemoteArchive($sourceNode, $remoteArchive);
            @$this->cleanupRemoteArchive($targetNode, $remoteArchive);

            Log::error("Container migration failed for service {$service->id}: ".$e->getMessage());
            throw $e;
        } finally {
            if (is_file($localArchive)) {
                @unlink($localArchive);
            }
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
                Log::error("Failed to migrate service {$service->id}: ".$e->getMessage());
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

    private function packContainerOnNode(Node $node, string $containerName, string $remoteArchive): void
    {
        $ssh = SSHService::forNode($node);
        try {
            $containerPath = self::CONTAINER_BASE_PATH.'/'.$containerName;
            $ssh->exec('mkdir -p '.self::MIGRATE_BASE_PATH);
            @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml stop", 120);
            $ssh->exec(
                'tar -czf '.escapeshellarg($remoteArchive).' -C '.self::CONTAINER_BASE_PATH.' '.escapeshellarg($containerName),
                self::TRANSFER_TIMEOUT
            );
        } finally {
            $ssh->disconnect();
        }
    }

    private function transferArchive(Node $source, Node $target, string $remoteArchive, string $localArchive): void
    {
        $dir = dirname($localArchive);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $sourceSsh = SSHService::forNode($source);
        try {
            $sourceSsh->downloadToLocal($remoteArchive, $localArchive);
        } finally {
            $sourceSsh->disconnect();
        }

        if (! is_file($localArchive) || filesize($localArchive) < 1) {
            throw new Exception('Failed to download migration archive from source node');
        }

        $targetSsh = SSHService::forNode($target);
        try {
            $targetSsh->exec('mkdir -p '.self::MIGRATE_BASE_PATH);
            $targetSsh->uploadFromLocal($localArchive, $remoteArchive);
        } finally {
            $targetSsh->disconnect();
        }
    }

    private function unpackContainerOnNode(Node $node, string $containerName, string $remoteArchive): void
    {
        $ssh = SSHService::forNode($node);
        try {
            $containerPath = self::CONTAINER_BASE_PATH.'/'.$containerName;
            @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down -v", 120);
            @$ssh->deleteDir($containerPath);
            $ssh->exec('mkdir -p '.self::CONTAINER_BASE_PATH);
            $ssh->exec(
                'tar -xzf '.escapeshellarg($remoteArchive).' -C '.self::CONTAINER_BASE_PATH,
                self::TRANSFER_TIMEOUT
            );
        } finally {
            $ssh->disconnect();
        }
    }

    private function cleanupRemoteArchive(Node $node, string $remoteArchive): void
    {
        try {
            $ssh = SSHService::forNode($node);
            try {
                @$ssh->exec('rm -f '.escapeshellarg($remoteArchive));
            } finally {
                $ssh->disconnect();
            }
        } catch (Exception $e) {
            Log::warning('Failed to clean migration archive', [
                'node_id' => $node->id,
                'path' => $remoteArchive,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cleanupOldDeployment(string $containerName, ?Node $sourceNode): void
    {
        if (! $sourceNode) {
            return;
        }

        try {
            $ssh = SSHService::forNode($sourceNode);
            $containerPath = self::CONTAINER_BASE_PATH.'/'.$containerName;

            try {
                @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down -v", 120);
                @$ssh->deleteDir($containerPath);
            } finally {
                $ssh->disconnect();
            }
        } catch (Exception $e) {
            Log::warning("Post-migration cleanup failed for {$containerName}", [
                'node_id' => $sourceNode->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
