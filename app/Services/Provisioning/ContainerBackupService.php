<?php

namespace App\Services\Provisioning;

use App\Jobs\CreateContainerBackupJob;
use App\Models\ContainerBackup;
use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    public function queueBackup(
        Service $service,
        string $type = 'manual',
        bool $afterResponse = true,
    ): ContainerBackup {
        $deployment = $service->containerDeployment;

        if (! $deployment || ! $deployment->node) {
            throw new Exception('Container deployment not found for service');
        }

        try {
            $backup = Cache::lock($this->backupLockName($service), 30)->block(5, function () use ($service, $deployment, $type) {
                if (ContainerBackup::query()
                    ->where('service_id', $service->id)
                    ->whereIn('status', ['pending', 'running'])
                    ->exists()) {
                    throw new Exception('A backup is already queued or running for this service. Refresh the Backups tab shortly.');
                }

                return $this->createBackupRecord($service, $deployment, $deployment->node, $type);
            });
        } catch (LockTimeoutException) {
            throw new Exception('A backup is already being started for this service. Refresh the Backups tab shortly.');
        }

        $dispatch = CreateContainerBackupJob::dispatch($backup->id);
        if ($afterResponse) {
            $dispatch->afterResponse();
        }

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

        try {
            return Cache::lock($this->backupLockName($service), self::BACKUP_TIMEOUT + 300)
                ->block(5, fn () => $this->performBackup($service, $backup));
        } catch (LockTimeoutException) {
            throw new Exception('Another backup operation is already running for this service.');
        }
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

        try {
            return Cache::lock($this->backupLockName($service), self::BACKUP_TIMEOUT + 300)
                ->block(5, function () use ($service, $deployment, $type) {
                    if (ContainerBackup::query()
                        ->where('service_id', $service->id)
                        ->whereIn('status', ['pending', 'running'])
                        ->exists()) {
                        throw new Exception('A backup is already queued or running for this service.');
                    }

                    $backup = $this->createBackupRecord($service, $deployment, $deployment->node, $type);

                    return $this->performBackup($service, $backup);
                });
        } catch (LockTimeoutException) {
            throw new Exception('Another backup operation is already running for this service.');
        }
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
        $ssh = null;

        try {
            $ssh = $this->sshFor($node);
            $ssh->exec('mkdir -p '.self::BACKUP_BASE_PATH);

            $backup->update(['status' => 'running']);

            $deploymentService = new ContainerDeploymentService;
            $deploymentService->ensureComposeFileExists($ssh, $deployment);

            $dumpMetadata = [];
            try {
                if ($this->shouldIncludeMysqlDump($service)) {
                    $dumpMetadata = $this->dumpMysqlIntoContainerTree($ssh, $service, $deployment);
                }

                // Live archive (no stop/start). Large WP sites spent hours downtimed just so
                // we could relay the tarball through the app server twice.
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
                    'metadata' => array_filter(array_merge(
                        is_array($backup->metadata) ? $backup->metadata : [],
                        $dumpMetadata,
                    )),
                ]);

                Log::info('Container backup created successfully', [
                    'service_id' => $service->id,
                    'backup_id' => $backup->id,
                    'backup_name' => $backup->backup_name,
                    'storage_driver' => $storageDriver,
                    'size_bytes' => $size,
                    'includes_database' => (bool) ($dumpMetadata['includes_database'] ?? false),
                    'live' => true,
                ]);
            } finally {
                $this->cleanupBackupDumpDir($ssh, $containerName);
                $ssh->disconnect();
                $this->hetzner->disconnect();
            }
        } catch (\Throwable $e) {
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
        } finally {
            $ssh?->disconnect();
            $this->hetzner->disconnect();
        }

        return $backup->fresh();
    }

    private function backupLockName(Service $service): string
    {
        return 'container-backup:service:'.$service->id;
    }

    private function createBackupRecord(Service $service, $deployment, Node $node, string $type): ContainerBackup
    {
        $backupName = 'backup-'.$service->id.'-'.now()->format('YmdHisv').'-'.Str::lower(Str::random(8));
        $localBackupPath = self::BACKUP_BASE_PATH.'/'.$backupName.'.tar.gz';

        return ContainerBackup::create([
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
        $ssh = null;
        $swapped = false;
        $restoreId = 'restore-'.$backup->id.'-'.Str::lower(Str::random(8));
        $stagingRoot = self::BACKUP_BASE_PATH.'/'.$restoreId;
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $rollbackPath = $containerPath.'.rollback-'.$restoreId;
        $deploymentService = new ContainerDeploymentService;
        $restoreFinalized = false;

        register_shutdown_function(function () use (
            &$restoreFinalized,
            &$swapped,
            &$ssh,
            $backup,
            $service,
            $deployment,
            $deploymentService,
            $containerPath,
            $rollbackPath
        ): void {
            if ($restoreFinalized || ! $swapped || ! $ssh) {
                return;
            }

            $fatal = error_get_last();
            if (! is_array($fatal) || ! in_array($fatal['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }

            $this->rollbackRestoreSwap(
                $ssh,
                $deploymentService,
                $service,
                $deployment,
                $containerPath,
                $rollbackPath,
                $backup->id
            );

            try {
                $backup->update([
                    'status' => 'failed',
                    'error_message' => 'Restore interrupted by fatal shutdown: '.($fatal['message'] ?? 'unknown error'),
                ]);
            } catch (\Throwable) {
                // The database may be unavailable during process shutdown.
            }
        });

        try {
            $ssh = $this->sshFor($node);

            $backup->update(['status' => 'restoring']);

            if (($backup->storage_driver ?? 'node') === 'hetzner') {
                $this->stageHetznerBackupOnNode($ssh, $backup, $localArchive);
                $cleanupLocal = true;
            }

            $archiveOnNode = ($backup->storage_driver ?? 'node') === 'hetzner'
                ? $localArchive
                : $backup->backup_path;

            // Validate both gzip/tar integrity and member paths before touching the live workload.
            $ssh->exec(
                $this->buildArchiveValidationCommand((string) $archiveOnNode, (string) $deployment->container_name),
                self::BACKUP_TIMEOUT
            );

            // Extract into an isolated staging root. The live directory remains untouched until
            // the complete archive has been validated and extracted.
            $ssh->exec(
                'rm -rf '.escapeshellarg($stagingRoot)
                .' && mkdir -p '.escapeshellarg($stagingRoot)
                .' && tar -xzf '.escapeshellarg((string) $archiveOnNode)
                .' -C '.escapeshellarg($stagingRoot)
                .' && test -d '.escapeshellarg($stagingRoot.'/'.$deployment->container_name),
                self::BACKUP_TIMEOUT
            );

            $deploymentService->ensureComposeFileExists($ssh, $deployment);
            $ssh->reconnect();
            $ssh->exec(
                'cd '.escapeshellarg($containerPath).' && docker compose -f docker-compose.yml down',
                120
            );

            $ssh->exec(
                'rm -rf '.escapeshellarg($rollbackPath)
                .' && mv '.escapeshellarg($containerPath).' '.escapeshellarg($rollbackPath)
                .' && (mv '.escapeshellarg($stagingRoot.'/'.$deployment->container_name).' '.escapeshellarg($containerPath)
                .' || { mv '.escapeshellarg($rollbackPath).' '.escapeshellarg($containerPath).'; exit 1; })',
                120
            );
            $swapped = true;

            $ssh->reconnect();
            $deploymentService->startComposeStack($ssh, $service, $deployment);
            $deploymentService->waitForContainerRunning($ssh, $deployment->container_name, 120);

            $safetyDumpPath = null;
            try {
                $safetyDumpPath = $this->restoreMysqlDumpIfPresent(
                    $ssh,
                    $service,
                    $deployment,
                    $containerPath,
                    $restoreId,
                );
            } catch (\Throwable $dbError) {
                if ($safetyDumpPath) {
                    $this->softExec($ssh, 'rm -f '.escapeshellarg($safetyDumpPath), 30);
                }

                throw $dbError;
            }

            $ssh->exec('rm -rf '.escapeshellarg($rollbackPath), 120);
            $swapped = false;
            if ($safetyDumpPath) {
                $this->softExec($ssh, 'rm -f '.escapeshellarg($safetyDumpPath), 30);
            }

            $backup->update(['status' => 'completed']);

            Log::info('Container restored from backup', [
                'backup_id' => $backup->id,
                'service_id' => $backup->service_id,
                'deployment_id' => $deployment->id,
                'storage_driver' => $backup->storage_driver,
                'includes_database' => (bool) (($backup->metadata['includes_database'] ?? false)),
            ]);

        } catch (\Throwable $e) {
            if ($ssh && $swapped) {
                $this->rollbackRestoreSwap(
                    $ssh,
                    $deploymentService,
                    $service,
                    $deployment,
                    $containerPath,
                    $rollbackPath,
                    $backup->id
                );
            }

            $backup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            Log::error("Container restore failed for backup {$backup->id}", [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($ssh) {
                $this->softExec($ssh, 'rm -rf '.escapeshellarg($stagingRoot), 30);
                if ($cleanupLocal) {
                    $this->softExec($ssh, 'rm -f '.escapeshellarg($localArchive), 30);
                }
                $ssh->disconnect();
            }
            $this->hetzner->disconnect();
            $restoreFinalized = true;
        }
    }

    private function rollbackRestoreSwap(
        SSHService $ssh,
        ContainerDeploymentService $deploymentService,
        Service $service,
        $deployment,
        string $containerPath,
        string $rollbackPath,
        int $backupId
    ): void {
        try {
            $ssh->reconnect();
            $this->softExec(
                $ssh,
                'cd '.escapeshellarg($containerPath).' && docker compose -f docker-compose.yml down',
                120
            );
            $ssh->exec(
                'rm -rf '.escapeshellarg($containerPath)
                .' && mv '.escapeshellarg($rollbackPath).' '.escapeshellarg($containerPath),
                120
            );
            $ssh->reconnect();
            $deploymentService->startComposeStack($ssh, $service, $deployment);
            $deploymentService->waitForContainerRunning($ssh, $deployment->container_name, 120);
        } catch (\Throwable $rollbackError) {
            Log::critical('Container restore rollback failed', [
                'backup_id' => $backupId,
                'error' => $rollbackError->getMessage(),
            ]);
        }
    }

    public function buildArchiveValidationCommand(string $archivePath, string $containerName): string
    {
        $archive = escapeshellarg($archivePath);
        $expected = escapeshellarg($containerName);

        return 'listing=$(mktemp) && trap \'rm -f "$listing"\' EXIT'
            .' && tar -tzf '.$archive.' > "$listing"'
            .' && test -s "$listing"'
            .' && ! awk \'BEGIN { bad=0 } /^\// || /(^|\/)\.\.(\/|$)/ { bad=1 } END { exit bad ? 0 : 1 }\' "$listing"'
            .' && awk -v root='.$expected.' \'BEGIN { found=0 } { sub(/^\.\//, "", $0); split($0, p, "/"); if (p[1] == root) found=1 } END { exit found ? 0 : 1 }\' "$listing"';
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
            } catch (Exception $e) {
                Log::error('Failed to delete backup file from Hetzner Storage Box', [
                    'backup_id' => $backup->id,
                    'backup_path' => $backup->backup_path,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            } finally {
                $this->hetzner->disconnect();
            }

            return;
        }

        $node = $backup->node;

        if (! $node) {
            throw new Exception('Backup node is missing; backup record was retained.');
        }

        $ssh = null;
        try {
            $ssh = $this->sshFor($node);
            $ssh->exec('rm -f '.escapeshellarg($backup->backup_path));
        } catch (Exception $e) {
            Log::error("Failed to delete backup file from node {$node->id}", [
                'backup_id' => $backup->id,
                'backup_path' => $backup->backup_path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $ssh?->disconnect();
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

    public function shouldIncludeMysqlDump(Service $service): bool
    {
        $slug = $service->effectiveContainerTemplate()?->slug
            ?? $service->product?->containerTemplate?->slug;

        return $slug === 'wordpress';
    }

    public function backupDumpRelativePath(): string
    {
        return '.talksasa-backup/database.sql';
    }

    /**
     * @return array{includes_database: bool, database_engine: string, dump_member: string, database_name: string}
     */
    public function dumpMysqlIntoContainerTree(SSHService $ssh, Service $service, $deployment): array
    {
        $containerPath = self::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $dumpRelative = $this->backupDumpRelativePath();
        $dumpPath = $containerPath.'/'.$dumpRelative;
        $dumpDir = dirname($dumpPath);

        $migration = app(DirectAdminToContainerMigrationService::class);
        $creds = $migration->resolveWordpressImportCredentials($service, $ssh, $containerPath);
        $live = $migration->readLiveMysqlSidecarEnv($ssh, $containerPath, $creds['service']);

        $dbService = (string) $creds['service'];
        $database = (string) ($live['MYSQL_DATABASE'] ?? $creds['database'] ?: 'wordpress');
        $rootPassword = (string) ($live['MYSQL_ROOT_PASSWORD'] ?? $creds['root_password'] ?? '');
        $appPassword = (string) ($live['MYSQL_PASSWORD'] ?? $creds['password'] ?? '');
        $appUser = (string) ($live['MYSQL_USER'] ?? $creds['user'] ?? 'wordpress');

        $user = $rootPassword !== '' ? 'root' : $appUser;
        $password = $rootPassword !== '' ? $rootPassword : $appPassword;

        if ($password === '') {
            throw new Exception('WordPress database credentials are missing; cannot include MySQL dump in backup.');
        }

        $migration->waitForComposeMysql($ssh, $containerPath, $dbService, $password, 180, $user);

        $ssh->exec(
            'mkdir -p '.escapeshellarg($dumpDir)
            .' && rm -f '.escapeshellarg($dumpPath),
            30
        );

        $ssh->exec(
            $this->buildComposeMysqlDumpCommand(
                $containerPath,
                $dbService,
                $user,
                $password,
                $database,
                $dumpPath,
            ),
            self::BACKUP_TIMEOUT
        );

        $size = (int) trim($ssh->exec(
            'if [ -s '.escapeshellarg($dumpPath).' ]; then wc -c < '.escapeshellarg($dumpPath).'; else echo 0; fi',
            15
        ));

        if ($size < 32) {
            throw new Exception('WordPress MySQL dump was empty or missing; backup aborted.');
        }

        return [
            'includes_database' => true,
            'database_engine' => 'mysql',
            'dump_member' => $deployment->container_name.'/'.$dumpRelative,
            'database_name' => $database,
        ];
    }

    public function buildComposeMysqlDumpCommand(
        string $containerPath,
        string $dbService,
        string $user,
        string $password,
        string $database,
        string $dumpPath,
    ): string {
        return 'cd '.escapeshellarg($containerPath)
            .' && docker compose exec -T -e MYSQL_PWD='.escapeshellarg($password)
            .' '.escapeshellarg($dbService)
            .' mysqldump -u'.escapeshellarg($user)
            .' --single-transaction --quick --no-tablespaces --routines --triggers'
            .' '.escapeshellarg($database)
            .' > '.escapeshellarg($dumpPath);
    }

    private function cleanupBackupDumpDir(SSHService $ssh, string $containerName): void
    {
        $dumpDir = self::CONTAINER_BASE_PATH.'/'.$containerName.'/.talksasa-backup';
        $this->softExec($ssh, 'rm -rf '.escapeshellarg($dumpDir), 30);
    }

    /**
     * Import a backup SQL dump if present. Creates a safety dump first so a failed
     * import can restore the prior database before file rollback.
     *
     * @return string|null Absolute path to the temporary safety dump (caller deletes on success)
     */
    public function restoreMysqlDumpIfPresent(
        SSHService $ssh,
        Service $service,
        $deployment,
        string $containerPath,
        string $restoreId,
    ): ?string {
        $dumpPath = $containerPath.'/'.$this->backupDumpRelativePath();
        $exists = trim($ssh->exec(
            '[ -s '.escapeshellarg($dumpPath).' ] && echo yes || echo no',
            15
        ));

        if ($exists !== 'yes') {
            return null;
        }

        if (! $this->shouldIncludeMysqlDump($service)) {
            // Legacy/non-WP archive accidentally containing the member — ignore safely.
            $this->cleanupBackupDumpDir($ssh, (string) $deployment->container_name);

            return null;
        }

        $migration = app(DirectAdminToContainerMigrationService::class);
        $creds = $migration->resolveWordpressImportCredentials($service, $ssh, $containerPath);
        $live = $migration->readLiveMysqlSidecarEnv($ssh, $containerPath, $creds['service']);

        $dbService = (string) $creds['service'];
        $database = (string) ($live['MYSQL_DATABASE'] ?? $creds['database'] ?: 'wordpress');
        $rootPassword = (string) ($live['MYSQL_ROOT_PASSWORD'] ?? $creds['root_password'] ?? '');
        $appPassword = (string) ($live['MYSQL_PASSWORD'] ?? $creds['password'] ?? '');
        $appUser = (string) ($live['MYSQL_USER'] ?? $creds['user'] ?? 'wordpress');

        $user = $rootPassword !== '' ? 'root' : $appUser;
        $password = $rootPassword !== '' ? $rootPassword : $appPassword;

        if ($password === '') {
            throw new Exception('WordPress database credentials are missing; cannot restore MySQL dump.');
        }

        $migration->waitForComposeMysql($ssh, $containerPath, $dbService, $password, 180, $user);

        $safetyDumpPath = self::BACKUP_BASE_PATH.'/safety-'.$restoreId.'.sql';
        $ssh->exec(
            $this->buildComposeMysqlDumpCommand(
                $containerPath,
                $dbService,
                $user,
                $password,
                $database,
                $safetyDumpPath,
            ),
            self::BACKUP_TIMEOUT
        );

        try {
            $this->recreateMysqlDatabase($ssh, $migration, $containerPath, $dbService, $user, $password, $database, $appUser);
            $ssh->exec(
                $migration->buildMysqlDumpImportCommand(
                    $containerPath,
                    $dbService,
                    $dumpPath,
                    $user,
                    $password,
                    $database,
                ),
                self::BACKUP_TIMEOUT
            );
            $this->cleanupBackupDumpDir($ssh, (string) $deployment->container_name);
        } catch (\Throwable $e) {
            try {
                $this->recreateMysqlDatabase($ssh, $migration, $containerPath, $dbService, $user, $password, $database, $appUser);
                $ssh->exec(
                    $migration->buildMysqlDumpImportCommand(
                        $containerPath,
                        $dbService,
                        $safetyDumpPath,
                        $user,
                        $password,
                        $database,
                    ),
                    self::BACKUP_TIMEOUT
                );
            } catch (\Throwable $safetyError) {
                Log::critical('Failed to restore WordPress safety database dump after import failure', [
                    'service_id' => $service->id,
                    'error' => $safetyError->getMessage(),
                ]);
            }

            $this->softExec($ssh, 'rm -f '.escapeshellarg($safetyDumpPath), 30);

            throw new Exception(
                'WordPress database restore failed: '.$e->getMessage(),
                previous: $e
            );
        }

        return $safetyDumpPath;
    }

    private function recreateMysqlDatabase(
        SSHService $ssh,
        DirectAdminToContainerMigrationService $migration,
        string $containerPath,
        string $dbService,
        string $user,
        string $password,
        string $database,
        string $appUser,
    ): void {
        $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $database) ?: 'wordpress';
        $safeAppUser = preg_replace('/[^a-zA-Z0-9_]/', '', $appUser) ?: 'wordpress';

        $sql = "DROP DATABASE IF EXISTS `{$safeDb}`; CREATE DATABASE `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
        if ($user === 'root' && $safeAppUser !== '') {
            $sql .= " GRANT ALL PRIVILEGES ON `{$safeDb}`.* TO '{$safeAppUser}'@'%'; FLUSH PRIVILEGES;";
        }

        $migration->execMysqlInCompose(
            $ssh,
            $containerPath,
            $dbService,
            $user,
            $password,
            $sql,
            null,
            120
        );
    }
}
