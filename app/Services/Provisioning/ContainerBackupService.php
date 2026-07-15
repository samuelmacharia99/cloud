<?php

namespace App\Services\Provisioning;

use App\Models\ContainerBackup;
use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ContainerBackupService
{
    private const CONTAINER_BASE_PATH = '/opt/talksasa/containers';

    private const BACKUP_BASE_PATH = '/opt/talksasa/backups';

    private const BACKUP_TIMEOUT = 3600; // 60 minutes — large WP volumes need longer than PHP-FPM ever allows

    /** @var callable(Node): SSHService|null */
    private $sshFactory = null;

    public function __construct(
        private ?HetznerStorageBoxClient $hetzner = null,
    ) {
        $this->hetzner ??= new HetznerStorageBoxClient;
    }

    /**
     * @param  callable(Node): SSHService  $factory
     */
    public function usingSshFactory(callable $factory): self
    {
        $this->sshFactory = $factory;

        return $this;
    }

    private function sshFor(Node $node): SSHService
    {
        if ($this->sshFactory) {
            return ($this->sshFactory)($node);
        }

        return SSHService::forNode($node);
    }

    /**
     * Queue a manual backup so tar/Hetzner offload are not bound by PHP-FPM's 30s limit.
     */
    public function queueBackup(Service $service, string $type = 'manual'): ContainerBackup
    {
        $deployment = $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            throw new Exception('Container deployment not found for service');
        }

        $inFlight = ContainerBackup::query()
            ->where('service_id', $service->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($inFlight) {
            throw new Exception('A backup is already queued or running for this service. Refresh the Backups tab shortly.');
        }

        $backupName = 'backup-'.$service->id.'-'.now()->format('YmdHis');
        $localBackupPath = self::BACKUP_BASE_PATH.'/'.$backupName.'.tar.gz';

        $backup = ContainerBackup::create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => $deployment->node->id,
            'backup_name' => $backupName,
            'backup_path' => $localBackupPath,
            'storage_driver' => 'node',
            'status' => 'pending',
            'type' => $type,
            'started_at' => now(),
        ]);

        \App\Jobs\CreateContainerBackupJob::dispatch($backup->id)->afterResponse();

        return $backup;
    }

    /**
     * Execute a previously queued backup row (used by CreateContainerBackupJob).
     */
    public function runQueuedBackup(ContainerBackup $backup): ContainerBackup
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $backup->loadMissing('service.containerDeployment.node');
        $service = $backup->service;
        if (! $service) {
            throw new Exception('Backup service is missing.');
        }

        return $this->performBackup($service, $backup);
    }

    /**
     * Create a manual or scheduled backup of a container (synchronous — cron / jobs).
     */
    public function createBackup(Service $service, string $type = 'manual'): ContainerBackup
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $deployment = $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            throw new Exception('Container deployment not found for service');
        }

        $node = $deployment->node;
        $backupName = 'backup-'.$service->id.'-'.now()->format('YmdHis');
        $localBackupPath = self::BACKUP_BASE_PATH.'/'.$backupName.'.tar.gz';

        $backup = ContainerBackup::create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => $node->id,
            'backup_name' => $backupName,
            'backup_path' => $localBackupPath,
            'storage_driver' => 'node',
            'status' => 'pending',
            'type' => $type,
            'started_at' => now(),
        ]);

        return $this->performBackup($service, $backup);
    }

    /**
     * @throws Exception
     */
    private function performBackup(Service $service, ContainerBackup $backup): ContainerBackup
    {
        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->node) {
            throw new Exception('Container deployment not found for service');
        }

        $node = $deployment->node;
        $localBackupPath = (string) $backup->backup_path;
        $containerName = (string) $deployment->container_name;

        try {
            $ssh = $this->sshFor($node);
            $ssh->exec('mkdir -p '.self::BACKUP_BASE_PATH);

            $backup->update(['status' => 'running']);

            $deploymentService = new ContainerDeploymentService;
            $deploymentService->ensureComposeFileExists($ssh, $deployment);

            // Live archive (no stop/start). Large WP sites spent hours downtimed just so
            // we could relay the tarball through the app server twice.
            try {
                $finalPath = $localBackupPath;
                $storageDriver = 'node';
                $size = 0;

                if ($this->hetzner->usesHetzner() && $this->hetzner->isConfigured()) {
                    try {
                        $remotePath = $this->hetzner->remotePathFor(basename($localBackupPath));
                        $size = $this->archiveDirectlyToHetzner($ssh, $containerName, $remotePath);
                        $finalPath = $remotePath;
                        $storageDriver = 'hetzner';
                    } catch (\Throwable $directError) {
                        Log::warning('Direct node→Hetzner backup failed; falling back to node archive + platform relay', [
                            'backup_id' => $backup->id,
                            'error' => $directError->getMessage(),
                        ]);

                        $size = $this->createNodeArchive($ssh, $containerName, $localBackupPath);

                        try {
                            $finalPath = $this->offloadToHetzner($ssh, $backup, $localBackupPath);
                            $storageDriver = 'hetzner';
                            $size = $this->hetzner->remoteFilesize($finalPath);
                        } catch (\Throwable $offloadError) {
                            Log::error('Hetzner offload failed; keeping node copy', [
                                'backup_id' => $backup->id,
                                'error' => $offloadError->getMessage(),
                            ]);
                            $finalPath = $localBackupPath;
                            $storageDriver = 'node';
                            $backup->error_message = 'Archive saved on node; Hetzner upload failed: '.$offloadError->getMessage();
                        }
                    }
                } else {
                    $size = $this->createNodeArchive($ssh, $containerName, $localBackupPath);
                }

                $backup->update([
                    'status' => 'completed',
                    'backup_path' => $finalPath,
                    'storage_driver' => $storageDriver,
                    'size_bytes' => $size,
                    'completed_at' => now(),
                    'error_message' => $storageDriver === 'hetzner' ? null : ($backup->error_message ?? null),
                ]);

                Log::info('Container backup created successfully', [
                    'service_id' => $service->id,
                    'backup_id' => $backup->id,
                    'backup_name' => $backup->backup_name,
                    'storage_driver' => $storageDriver,
                    'size_bytes' => $size,
                    'live' => true,
                ]);
            } finally {
                $ssh->disconnect();
                $this->hetzner->disconnect();
            }
        } catch (Exception $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error("Container backup failed for service {$service->id}", [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $backup->fresh();
    }

    /**
     * Create a compressed archive on the container node (excludes caches / junk).
     */
    private function createNodeArchive(SSHService $ssh, string $containerName, string $localBackupPath): int
    {
        $ssh->exec(
            $this->buildTarCreateCommand($containerName, $localBackupPath),
            self::BACKUP_TIMEOUT
        );

        $sizeOutput = $ssh->exec('du -b '.escapeshellarg($localBackupPath).' | cut -f1');

        return (int) trim($sizeOutput);
    }

    /**
     * Tar on the node, then push node → Hetzner (no app-server hop).
     *
     * @return int Remote file size in bytes
     */
    private function archiveDirectlyToHetzner(SSHService $ssh, string $containerName, string $remotePath): int
    {
        $this->hetzner->ensureBaseDirectoryExists();

        $config = $this->hetzner->connectionConfig();
        $localBackupPath = self::BACKUP_BASE_PATH.'/'.basename($remotePath);
        $this->createNodeArchive($ssh, $containerName, $localBackupPath);

        $netrcFile = '/tmp/talksasa-hetzner-'.bin2hex(random_bytes(6));
        $passFile = $netrcFile.'.pass';
        $netrc = "machine {$config['host']}\nlogin {$config['username']}\npassword {$config['password']}\n";
        $errors = [];
        $uploaded = false;

        try {
            $ssh->upload($netrc, $netrcFile);
            $ssh->upload($config['password']."\n", $passFile);
            $ssh->exec('chmod 600 '.escapeshellarg($netrcFile).' '.escapeshellarg($passFile));

            $curlSupportsSftp = str_contains(
                strtolower($ssh->exec('curl -V 2>&1 || true')),
                'sftp'
            );

            if ($curlSupportsSftp) {
                try {
                    $url = sprintf(
                        'sftp://%s:%d/%s',
                        $config['host'],
                        $config['port'],
                        ltrim($remotePath, '/')
                    );
                    $ssh->exec(
                        'curl --fail --show-error --connect-timeout 30 --max-time '
                        .self::BACKUP_TIMEOUT
                        .' --netrc-file '.escapeshellarg($netrcFile)
                        .' --upload-file '.escapeshellarg($localBackupPath).' '
                        .escapeshellarg($url),
                        self::BACKUP_TIMEOUT
                    );
                    $uploaded = true;
                } catch (\Throwable $e) {
                    $errors[] = 'curl: '.$e->getMessage();
                }
            } else {
                $errors[] = 'curl has no sftp protocol';
            }

            if (! $uploaded) {
                $hasSshpass = trim($ssh->exec('command -v sshpass >/dev/null && echo yes || echo no')) === 'yes';
                if ($hasSshpass) {
                    try {
                        $dest = escapeshellarg(
                            $config['username'].'@'.$config['host'].':'.ltrim($remotePath, '/')
                        );
                        $ssh->exec(
                            'sshpass -f '.escapeshellarg($passFile)
                            .' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -P '
                            .(int) $config['port'].' '
                            .escapeshellarg($localBackupPath).' '.$dest,
                            self::BACKUP_TIMEOUT
                        );
                        $uploaded = true;
                    } catch (\Throwable $e) {
                        $errors[] = 'scp: '.$e->getMessage();
                    }
                } else {
                    $errors[] = 'sshpass not installed on node';
                }
            }

            if (! $uploaded) {
                throw new Exception(
                    'Could not upload from node to Hetzner ('.implode('; ', $errors)
                    .'). Install curl-with-sftp or sshpass on the container node.'
                );
            }

            $this->softExec($ssh, 'rm -f '.escapeshellarg($localBackupPath), 30);

            return $this->hetzner->remoteFilesize($remotePath);
        } finally {
            $this->softExec(
                $ssh,
                'rm -f '.escapeshellarg($netrcFile).' '.escapeshellarg($passFile),
                10
            );
        }
    }

    public function buildTarCreateCommand(string $containerName, string $archivePath): string
    {
        $excludes = [];
        foreach ([
            $containerName.'/app/wp-content/cache',
            $containerName.'/app/wp-content/upgrade',
            $containerName.'/app/wp-content/temp',
            $containerName.'/app/wp-content/tmp',
            $containerName.'/app/wp-content/wflogs',
            $containerName.'/app/wp-content/uploads/cache',
            $containerName.'/app/node_modules',
            $containerName.'/app/.git',
            $containerName.'/*.log',
        ] as $exclude) {
            $excludes[] = '--exclude='.escapeshellarg($exclude);
        }

        $tar = 'tar -czf '.escapeshellarg($archivePath)
            .' '.implode(' ', $excludes)
            .' -C '.escapeshellarg(self::CONTAINER_BASE_PATH)
            .' '.escapeshellarg($containerName);

        // Live backups often change underfoot; GNU tar exit 1 is OK if the archive exists.
        return $tar
            .' ; status=$?'
            .' ; if [ "$status" -eq 0 ] || [ "$status" -eq 1 ]; then'
            .'   if [ -s '.escapeshellarg($archivePath).' ]; then exit 0; fi'
            .' ; fi'
            .' ; exit "$status"';
    }

    /**
     * Restore a container from a backup
     */
    public function restoreBackup(ContainerBackup $backup): void
    {
        $deployment = $backup->deployment;
        $node = $backup->node;

        if (! $deployment || ! $node) {
            throw new Exception('Backup deployment or node not found');
        }

        $deployment->loadMissing('service');
        $service = $deployment->service;

        if (! $service) {
            throw new Exception('Backup service not found');
        }

        $localArchive = self::BACKUP_BASE_PATH.'/'.basename((string) $backup->backup_path);
        $cleanupLocal = false;

        try {
            $ssh = $this->sshFor($node);
            $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;

            // Update backup status
            $backup->update(['status' => 'restoring']);

            // Ensure docker-compose.yml exists before using it
            $deploymentService = new ContainerDeploymentService;
            $deploymentService->ensureComposeFileExists($ssh, $deployment);

            // Stop and remove current container
            $ssh->reconnect();
            $this->softExec($ssh, "cd {$containerPath} && docker compose -f docker-compose.yml down", 120);

            // Remove current directory
            @$ssh->deleteDir($containerPath);

            // Create fresh directory
            $ssh->mkdirp($containerPath);

            if (($backup->storage_driver ?? 'node') === 'hetzner') {
                $this->stageHetznerBackupOnNode($ssh, $backup, $localArchive);
                $cleanupLocal = true;
            }

            $archiveOnNode = ($backup->storage_driver ?? 'node') === 'hetzner'
                ? $localArchive
                : $backup->backup_path;

            // Extract backup tarball
            $ssh->exec(
                'tar -xzf '.escapeshellarg($archiveOnNode).' -C '.self::CONTAINER_BASE_PATH,
                self::BACKUP_TIMEOUT
            );

            if ($cleanupLocal) {
                $this->softExec($ssh, 'rm -f '.escapeshellarg($localArchive), 30);
            }

            // Restart container on a fresh SSH session after long extract
            $ssh->reconnect();
            $deploymentService->startComposeStack($ssh, $service, $deployment);

            // Wait a bit for health check
            sleep(5);

            // Update backup status
            $backup->update(['status' => 'completed']);

            Log::info('Container restored from backup', [
                'backup_id' => $backup->id,
                'service_id' => $backup->service_id,
                'deployment_id' => $deployment->id,
                'storage_driver' => $backup->storage_driver,
            ]);

            $ssh->disconnect();
            $this->hetzner->disconnect();
        } catch (Exception $e) {
            $backup->update(['status' => 'failed']);

            Log::error("Container restore failed for backup {$backup->id}", [
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
        $this->removeBackupFile($backup);

        // Mark backup as deleted in database
        $backup->update(['status' => 'deleted']);

        Log::info('Container backup marked as deleted', [
            'backup_id' => $backup->id,
        ]);
    }

    /**
     * Remove all backup files and database rows when a service is terminated.
     */
    public function purgeAllForService(Service $service): void
    {
        $backups = ContainerBackup::query()
            ->where('service_id', $service->id)
            ->get();

        foreach ($backups as $backup) {
            $this->purgeBackup($backup);
        }
    }

    /**
     * Delete backup tarball from storage and remove the database row.
     */
    public function purgeBackup(ContainerBackup $backup): void
    {
        $this->removeBackupFile($backup);

        $backupId = $backup->id;
        $serviceId = $backup->service_id;
        $backup->delete();

        Log::info('Container backup purged', [
            'backup_id' => $backupId,
            'service_id' => $serviceId,
        ]);
    }

    private function restartStackAfterBackup(
        SSHService $ssh,
        ContainerDeploymentService $deploymentService,
        Service $service,
        $deployment,
    ): void {
        // Fresh SSH after long tar — avoids phpseclib "close the channel" races.
        $ssh->reconnect();

        try {
            // Prefer start (containers already exist after stop) before a heavier up.
            $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
            $ssh->exec(
                'cd '.escapeshellarg($containerPath)
                .' && docker compose -f docker-compose.yml start',
                120
            );
        } catch (\Throwable $e) {
            Log::warning('compose start after backup failed; falling back to compose up', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
            $ssh->reconnect();
            $deploymentService->startComposeStack($ssh, $service, $deployment);
        }
    }

    private function softExec(SSHService $ssh, string $command, int $timeout = 60): void
    {
        try {
            $ssh->exec($command, $timeout);
        } catch (\Throwable $e) {
            Log::warning('Soft SSH exec failed during backup', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function offloadToHetzner(SSHService $ssh, ContainerBackup $backup, string $localBackupPath): string
    {
        if (! $this->hetzner->isConfigured()) {
            throw new Exception('Hetzner Storage Box is selected but not configured.');
        }

        $tmpDir = storage_path('app/tmp/backups');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $localTemp = $tmpDir.'/'.basename($localBackupPath);
        $remotePath = $this->hetzner->remotePathFor(basename($localBackupPath));

        try {
            $ssh->downloadToLocal($localBackupPath, $localTemp);

            if (! is_file($localTemp) || filesize($localTemp) <= 0) {
                throw new Exception('Downloaded backup from node is missing or empty before Hetzner upload.');
            }

            $this->hetzner->uploadFromLocal($localTemp, $remotePath);
            $ssh->reconnect();
            $this->softExec($ssh, 'rm -f '.escapeshellarg($localBackupPath), 30);
        } finally {
            if (is_file($localTemp)) {
                @unlink($localTemp);
            }
            $this->hetzner->disconnect();
        }

        Log::info('Container backup offloaded to Hetzner Storage Box', [
            'backup_id' => $backup->id,
            'remote_path' => $remotePath,
        ]);

        return $remotePath;
    }

    private function stageHetznerBackupOnNode(SSHService $ssh, ContainerBackup $backup, string $nodePath): void
    {
        $tmpDir = storage_path('app/tmp/backups');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $localTemp = $tmpDir.'/restore-'.basename((string) $backup->backup_path);

        try {
            $this->hetzner->downloadToLocal((string) $backup->backup_path, $localTemp);
            $ssh->exec('mkdir -p '.self::BACKUP_BASE_PATH);
            $ssh->uploadFromLocal($localTemp, $nodePath);
        } finally {
            if (is_file($localTemp)) {
                @unlink($localTemp);
            }
            $this->hetzner->disconnect();
        }
    }

    private function removeBackupFile(ContainerBackup $backup): void
    {
        if (! $backup->backup_path) {
            return;
        }

        if (($backup->storage_driver ?? 'node') === 'hetzner') {
            try {
                $this->hetzner->delete((string) $backup->backup_path);
                $this->hetzner->disconnect();
            } catch (Exception $e) {
                Log::warning('Failed to delete backup file from Hetzner Storage Box', [
                    'backup_id' => $backup->id,
                    'backup_path' => $backup->backup_path,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $node = $backup->node;

        if (! $node) {
            return;
        }

        try {
            $ssh = $this->sshFor($node);
            @$ssh->exec('rm -f '.escapeshellarg($backup->backup_path));
            $ssh->disconnect();
        } catch (Exception $e) {
            Log::warning("Failed to delete backup file from node {$node->id}", [
                'backup_id' => $backup->id,
                'backup_path' => $backup->backup_path,
                'error' => $e->getMessage(),
            ]);
        }
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
