<?php

namespace App\Services\Provisioning;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Database\Eloquent\Collection;
use Exception;

class ContainerBackupService
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';
    private const BACKUP_BASE_PATH = '/opt/talksasa/backups';
    private const BACKUP_TIMEOUT = 600; // 10 minutes

    /**
     * Create a manual or scheduled backup of a container
     */
    public function createBackup(Service $service, string $type = 'manual'): ContainerBackup
    {
        $deployment = $service->containerDeployment;

        if (!$deployment || !$deployment->node) {
            throw new Exception('Container deployment not found for service');
        }

        $node = $deployment->node;
        $backup = ContainerBackup::create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => $node->id,
            'backup_name' => 'backup-' . $service->id . '-' . now()->format('YmdHis'),
            'status' => 'pending',
            'type' => $type,
            'started_at' => now(),
        ]);

        try {
            $ssh = SSHService::forNode($node);

            // Create backup directory if needed
            $ssh->exec("mkdir -p " . self::BACKUP_BASE_PATH);

            // Build backup path
            $backupPath = self::BACKUP_BASE_PATH . '/' . $backup->backup_name . '.tar.gz';
            $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;

            // Update backup status to running
            $backup->update(['status' => 'running']);

            // Stop container briefly during backup
            @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml stop", 60);

            try {
                // Create tarball of container directory
                $ssh->exec(
                    "tar -czf {$backupPath} -C " . self::CONTAINER_BASE_PATH . " {$deployment->container_name}",
                    self::BACKUP_TIMEOUT
                );

                // Get backup size
                $sizeOutput = $ssh->exec("du -b {$backupPath} | cut -f1");
                $size = (int) trim($sizeOutput);

                // Restart container
                @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml start", 60);

                // Update backup record
                $backup->update([
                    'status' => 'completed',
                    'backup_path' => $backupPath,
                    'size_bytes' => $size,
                    'completed_at' => now(),
                ]);

                \Log::info("Container backup created successfully", [
                    'service_id' => $service->id,
                    'backup_id' => $backup->id,
                    'backup_name' => $backup->backup_name,
                    'size_bytes' => $size,
                ]);
            } catch (Exception $e) {
                // Make sure container is restarted even on backup failure
                @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml start", 60);
                throw $e;
            } finally {
                $ssh->disconnect();
            }
        } catch (Exception $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            \Log::error("Container backup failed for service {$service->id}", [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $backup;
    }

    /**
     * Restore a container from a backup
     */
    public function restoreBackup(ContainerBackup $backup): void
    {
        $deployment = $backup->deployment;
        $node = $backup->node;

        if (!$deployment || !$node) {
            throw new Exception('Backup deployment or node not found');
        }

        try {
            $ssh = SSHService::forNode($node);
            $containerPath = self::CONTAINER_BASE_PATH . '/' . $deployment->container_name;

            // Update backup status
            $backup->update(['status' => 'restoring']);

            // Stop and remove current container
            @$ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml down", 120);

            // Remove current directory
            @$ssh->deleteDir($containerPath);

            // Create fresh directory
            $ssh->mkdirp($containerPath);

            // Extract backup tarball
            $ssh->exec(
                "tar -xzf {$backup->backup_path} -C " . self::CONTAINER_BASE_PATH,
                self::BACKUP_TIMEOUT
            );

            // Restart container
            $ssh->exec("cd {$containerPath} && docker compose -f docker-compose.yml up -d", 120);

            // Wait a bit for health check
            sleep(5);

            // Update backup status
            $backup->update(['status' => 'completed']);

            \Log::info("Container restored from backup", [
                'backup_id' => $backup->id,
                'service_id' => $backup->service_id,
                'deployment_id' => $deployment->id,
            ]);

            $ssh->disconnect();
        } catch (Exception $e) {
            $backup->update(['status' => 'failed']);

            \Log::error("Container restore failed for backup {$backup->id}", [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a backup from storage
     */
    public function deleteBackup(ContainerBackup $backup): void
    {
        $node = $backup->node;

        if ($node && $backup->backup_path) {
            try {
                $ssh = SSHService::forNode($node);
                @$ssh->exec("rm -f {$backup->backup_path}");
                $ssh->disconnect();
            } catch (Exception $e) {
                \Log::warning("Failed to delete backup file from node {$node->id}", [
                    'backup_id' => $backup->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mark backup as deleted in database
        $backup->update(['status' => 'deleted']);

        \Log::info("Container backup marked as deleted", [
            'backup_id' => $backup->id,
        ]);
    }

    /**
     * List all backups for a node
     */
    public function listNodeBackups(Node $node): Collection
    {
        return ContainerBackup::where('node_id', $node->id)
            ->where('status', '!=', 'deleted')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * List all backups for a service
     */
    public function listServiceBackups(Service $service): Collection
    {
        return ContainerBackup::where('service_id', $service->id)
            ->where('status', '!=', 'deleted')
            ->orderByDesc('created_at')
            ->get();
    }
}
