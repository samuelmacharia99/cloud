<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDeploymentEvent;
use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ContainerMigrationService
{
    public function __construct(
        protected ?ContainerDeploymentService $deploymentService = null,
        protected ?ContainerMigrationBundleService $bundleService = null,
        protected ?Closure $sshFactory = null,
        protected ?ContainerMigrationProgress $progress = null,
    ) {
        $this->deploymentService ??= app(ContainerDeploymentService::class);
        $this->bundleService ??= app(ContainerMigrationBundleService::class);
        $this->progress ??= app(ContainerMigrationProgress::class);
    }

    /**
     * Migrate a container service to a different node, preserving volumes/app data.
     */
    public function migrate(Service $service, Node $targetNode, string $reason = 'manual'): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $lock = Cache::lock(
            app(ContainerNodeBuildService::class)->lockName($service),
            max(900, (int) config('containers.migration.operation_lock_seconds', 3600)),
        );
        if (! $lock->get()) {
            throw new Exception('Another deploy, build, pull, backup, or migration is already running for this service.');
        }
        $backupLock = Cache::lock(
            'container-backup:service:'.$service->id,
            max(900, (int) config('containers.migration.operation_lock_seconds', 3600)),
        );
        if (! $backupLock->get()) {
            $lock->release();
            throw new Exception('A backup is already queued or running for this service.');
        }

        try {
            return $this->migrateUnlocked($service, $targetNode, $reason);
        } finally {
            $backupLock->release();
            $lock->release();
        }
    }

    private function migrateUnlocked(Service $service, Node $targetNode, string $reason): array
    {
        $startedAt = microtime(true);
        $this->assertTargetNodeIsEligible($targetNode);
        $service->load(['containerDeployment.node', 'containerDeployment.domains', 'product.containerTemplate', 'user']);
        $oldDeployment = $service->containerDeployment;
        if (! $oldDeployment) {
            throw new Exception('Service has no active deployment');
        }
        $sourceNode = $oldDeployment->node;
        if (! $sourceNode) {
            throw new Exception('Source node is missing');
        }
        if (! $sourceNode->ssh_username || (! $sourceNode->ssh_password && ! $sourceNode->da_login_key)) {
            throw new Exception('Source node does not have working SSH credentials configured.');
        }
        if ($oldDeployment->node_id === $targetNode->id) {
            throw new Exception('Container is already on the target node');
        }

        $oldServiceStatus = $service->status;
        $oldNodeId = $oldDeployment->node_id;
        $oldDeploymentStatus = $oldDeployment->status;
        if ($oldDeploymentStatus !== 'running') {
            throw new Exception('Only a running deployment can be migrated with verified zero-loss cutover.');
        }
        $oldMigrationState = [
            'migrated_from_node_id' => $oldDeployment->migrated_from_node_id,
            'migrated_at' => $oldDeployment->migrated_at,
            'migration_reason' => $oldDeployment->migration_reason,
        ];
        $archiveName = 'migrate-'.$service->id.'-'.now()->format('YmdHis').'.tar.gz';
        $remoteArchive = ContainerMigrationBundleService::MIGRATION_PATH.'/'.$archiveName;
        $localArchive = storage_path('app/migrations/'.$archiveName);
        $sourceSsh = $this->ssh($sourceNode);
        try {
            $targetSsh = $this->ssh($targetNode);
        } catch (Throwable $e) {
            $sourceSsh->disconnect();
            throw $e;
        }
        $bundle = null;
        $preflight = null;
        $cutoverCommitted = false;
        $sourceStopped = false;
        $targetTouched = false;

        try {
            $this->progress->begin($service, $sourceNode, $targetNode, $reason);
            $this->progress->phase($service, 'preflight', 'Checking '.$targetNode->hostname.' before touching the running app');
            $preflight = $this->bundleService->preflight($sourceSsh, $targetSsh, $oldDeployment);
            $this->progress->log($service, sprintf(
                'Target accepted the move: %s to copy, %d named volume(s), %s free on %s.',
                ContainerMigrationProgress::formatBytes((int) $preflight['source_bytes']),
                count($preflight['volumes']),
                ContainerMigrationProgress::formatBytes((int) $preflight['target_free_bytes']),
                $targetNode->hostname,
            ));
            foreach ($preflight['volumes'] as $volumeName) {
                $this->progress->log($service, '  volume '.$volumeName.' will travel with the app');
            }
            $this->record($service, $oldDeployment, 'migration_started', [
                'source_node_id' => $oldNodeId,
                'target_node_id' => $targetNode->id,
                'reason' => $reason,
                'source_bytes' => $preflight['source_bytes'],
                'volume_count' => count($preflight['volumes']),
            ]);

            $service->update(['status' => 'provisioning']);
            $oldDeployment->update(['status' => 'deploying']);
            $sourceStopped = true;
            $this->progress->phase(
                $service,
                'snapshot',
                'Stopping the app on '.$sourceNode->hostname.' and snapshotting files and volumes',
            );
            $bundle = $this->bundleService->create(
                $sourceSsh,
                $oldDeployment,
                $preflight['volumes'],
                $remoteArchive,
            );
            $this->progress->log($service, sprintf(
                'Snapshot sealed: %s, sha256 %s.',
                ContainerMigrationProgress::formatBytes((int) $bundle['bytes']),
                substr((string) $bundle['checksum'], 0, 12),
            ));
            $this->record($service, $oldDeployment, 'migration_snapshot_created', [
                'archive_bytes' => $bundle['bytes'],
                'archive_checksum' => $bundle['checksum'],
                'volumes' => array_column($bundle['volumes'], 'name'),
            ]);

            $this->progress->phase(
                $service,
                'transfer',
                'Copying '.ContainerMigrationProgress::formatBytes((int) $bundle['bytes']).' to '.$targetNode->hostname,
                0,
                (int) $bundle['bytes'],
            );
            $this->bundleService->transfer($sourceSsh, $targetSsh, $remoteArchive, $localArchive, $bundle);
            $this->progress->log($service, 'Checksum matched on both hosts after transfer.');
            $this->record($service, $oldDeployment, 'migration_transfer_verified', [
                'archive_bytes' => $bundle['bytes'],
                'archive_checksum' => $bundle['checksum'],
            ]);

            $targetTouched = true;
            $this->progress->phase($service, 'restore', 'Unpacking files and restoring volumes on '.$targetNode->hostname);
            $this->bundleService->restore($targetSsh, $oldDeployment, $remoteArchive, $bundle);
            $this->progress->log($service, 'Every artifact verified against the manifest before it was written.');

            $this->progress->phase($service, 'start', 'Starting the stack on '.$targetNode->hostname);
            $this->deploymentService->ensureComposeFileExists($targetSsh, $oldDeployment);
            $this->deploymentService->startComposeStack($targetSsh, $service, $oldDeployment);

            $this->progress->phase($service, 'verify', 'Waiting for every container to report healthy');
            $this->waitForTargetReadiness($targetSsh, $service, $oldDeployment);
            $this->progress->log($service, 'Target stack is healthy.');
            $this->record($service, $oldDeployment, 'migration_target_ready', [
                'target_node_id' => $targetNode->id,
            ]);

            $this->progress->phase($service, 'cutover', 'Pointing the platform at '.$targetNode->hostname);

            DB::transaction(function () use (
                $service,
                $oldDeployment,
                $sourceNode,
                $targetNode,
                $oldNodeId,
                $reason,
                $oldServiceStatus,
            ): void {
                $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
                $lockedDeployment = ContainerDeployment::query()->lockForUpdate()->findOrFail($oldDeployment->id);
                $lockedSource = Node::query()->lockForUpdate()->findOrFail($sourceNode->id);
                $lockedTarget = Node::query()->lockForUpdate()->findOrFail($targetNode->id);

                $lockedService->update([
                    'node_id' => $targetNode->id,
                    'status' => $oldServiceStatus,
                ]);
                $lockedDeployment->update([
                    'node_id' => $targetNode->id,
                    'migrated_from_node_id' => $oldNodeId,
                    'migrated_at' => now(),
                    'migration_reason' => $reason,
                    'status' => 'running',
                ]);
                $lockedSource->update([
                    'container_count' => max(0, (int) $lockedSource->container_count - 1),
                ]);
                $lockedTarget->update([
                    'container_count' => (int) $lockedTarget->container_count + 1,
                ]);
            });
            $cutoverCommitted = true;

            $this->progress->phase($service, 'domains', 'Rebinding domains and checking they answer on the new host');
            $freshService = $service->fresh(['containerDeployment.node', 'containerDeployment.domains']);
            $freshDeployment = $freshService->containerDeployment;
            $this->deploymentService->rebindDeploymentDomainsStrict($freshService, $freshDeployment);
            $domainsVerified = $this->verifyPublicDomains($freshDeployment);
            $this->progress->log($service, $domainsVerified > 0
                ? $domainsVerified.' domain(s) answered from '.$targetNode->hostname.'.'
                : 'No public domains bound; skipped reachability checks.');

            $this->progress->phase($service, 'cleanup', 'Releasing the old copy on '.$sourceNode->hostname);

            try {
                $this->bundleService->stopAndRemoveTarget(
                    $sourceSsh,
                    $oldDeployment,
                    true,
                    array_column($bundle['volumes'], 'name'),
                );
            } catch (Throwable $cleanupError) {
                Log::warning('Source cleanup remains pending after successful migration', [
                    'service_id' => $service->id,
                    'source_node_id' => $oldNodeId,
                    'error' => $cleanupError->getMessage(),
                ]);
                $this->record($freshService, $freshDeployment, 'migration_source_cleanup_pending', [
                    'error' => $cleanupError->getMessage(),
                ]);
                $this->progress->log($service, 'The app is live on the new host, but the old copy could not be removed yet: '
                    .$cleanupError->getMessage());
            }

            $receipt = [
                'source_node_id' => $oldNodeId,
                'target_node_id' => $targetNode->id,
                'bytes' => (int) $bundle['bytes'],
                'volume_count' => count($bundle['volumes']),
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
                'domains_verified' => $domainsVerified,
            ];
            $this->record($freshService, $freshDeployment, 'migration_succeeded', $receipt);
            $this->progress->complete($service, sprintf(
                '%s is live on %s. %s and %d volume(s) moved and verified in %ss.',
                $service->name,
                $targetNode->hostname,
                ContainerMigrationProgress::formatBytes((int) $bundle['bytes']),
                count($bundle['volumes']),
                $receipt['duration_seconds'],
            ));
            Log::info("Container migrated for service {$service->id}", $receipt);

            return $receipt;
        } catch (Throwable $e) {
            $this->progress->log($service, 'Error: '.$e->getMessage());
            $this->progress->log($service, 'Rolling back so the app keeps running on '.$sourceNode->hostname.'…');

            try {
                $this->rollback(
                    $service,
                    $oldDeployment,
                    $sourceNode,
                    $targetNode,
                    $sourceSsh,
                    $targetSsh,
                    $oldNodeId,
                    $oldServiceStatus,
                    $oldDeploymentStatus,
                    $oldMigrationState,
                    $cutoverCommitted,
                    $sourceStopped,
                    $targetTouched,
                    is_array($bundle) ? array_column($bundle['volumes'], 'name') : [],
                );
                $this->record($service->fresh(), $oldDeployment->fresh(), 'migration_rolled_back', [
                    'error' => $e->getMessage(),
                ]);
                $this->progress->fail($service, $e->getMessage(), rolledBack: true);
            } catch (Throwable $rollbackError) {
                $service->update(['status' => 'failed']);
                $oldDeployment->update(['status' => 'failed']);
                $this->progress->fail(
                    $service,
                    'Migration failed and the source workload could not be restarted: '.$rollbackError->getMessage(),
                );
                Log::critical("Container migration rollback failed for service {$service->id}", [
                    'migration_error' => $e->getMessage(),
                    'rollback_error' => $rollbackError->getMessage(),
                ]);

                throw new Exception(
                    'Migration failed and the source workload could not be restarted: '.$rollbackError->getMessage(),
                    0,
                    $e
                );
            }

            Log::error("Container migration failed for service {$service->id}: ".$e->getMessage());

            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        } finally {
            foreach ([[$sourceSsh, $remoteArchive], [$targetSsh, $remoteArchive]] as [$ssh, $archive]) {
                try {
                    $this->bundleService->cleanup($ssh, $archive);
                } catch (Throwable $cleanupError) {
                    Log::warning('Failed to remove migration staging files', [
                        'service_id' => $service->id,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
            $sourceSsh->disconnect();
            $targetSsh->disconnect();
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
                ->where('status', 'running');
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
            ->whereNotNull('ssh_username')
            ->where(function ($query) {
                $query->whereNotNull('ssh_password')->orWhereNotNull('da_login_key');
            })
            ->orderBy('status', 'asc')
            ->orderByRaw('(SELECT COUNT(*) FROM container_deployments WHERE node_id = nodes.id AND status IN ("running", "stopped")) ASC')
            ->get();
    }

    private function assertTargetNodeIsEligible(Node $targetNode): void
    {
        if ($targetNode->type !== 'container_host') {
            throw new Exception('Target node is not a container host.');
        }
        if (! $targetNode->is_active) {
            throw new Exception('Target node is not active.');
        }
        if (! $targetNode->ssh_username || (! $targetNode->ssh_password && ! $targetNode->da_login_key)) {
            throw new Exception('Target node does not have working SSH credentials configured.');
        }
    }

    private function ssh(Node $node): SSHService
    {
        return $this->sshFactory
            ? ($this->sshFactory)($node)
            : SSHService::forNode($node);
    }

    private function waitForTargetReadiness(
        SSHService $ssh,
        Service $service,
        ContainerDeployment $deployment,
    ): void {
        $path = ContainerMigrationBundleService::BASE_PATH.'/'.$deployment->container_name;
        $ssh->exec(
            'cd '.escapeshellarg($path).' && '
                .'expected=$(docker compose -f docker-compose.yml config --services | wc -l); '
                .'running=$(docker compose -f docker-compose.yml ps --status running -q | wc -l); '
                .'[ "$expected" -gt 0 ] && [ "$running" -eq "$expected" ]; '
                .'! docker compose -f docker-compose.yml ps --format json 2>/dev/null '
                ."| grep -Eq '\"Health\":\"unhealthy\"|\"State\":\"(exited|restarting|dead)\"'",
            120,
        );

        $slug = $service->effectiveContainerTemplate()?->slug;
        if ($slug === 'nodejs') {
            $this->deploymentService->waitForNodeApplicationReadiness($ssh, $deployment, 180);
        } elseif ($slug === 'laravel') {
            $this->deploymentService->waitForLaravelHttpHealth($ssh, $deployment);
        } else {
            $this->deploymentService->waitForContainerRunning($ssh, $deployment->container_name, 120);
        }
    }

    /**
     * @param  array{migrated_from_node_id: mixed, migrated_at: mixed, migration_reason: mixed}  $oldMigrationState
     */
    private function rollback(
        Service $service,
        ContainerDeployment $deployment,
        Node $sourceNode,
        Node $targetNode,
        SSHService $sourceSsh,
        SSHService $targetSsh,
        int $oldNodeId,
        mixed $oldServiceStatus,
        string $oldDeploymentStatus,
        array $oldMigrationState,
        bool $cutoverCommitted,
        bool $sourceStopped,
        bool $targetTouched,
        array $volumeNames,
    ): void {
        if ($cutoverCommitted) {
            DB::transaction(function () use (
                $service,
                $deployment,
                $sourceNode,
                $targetNode,
                $oldNodeId,
                $oldMigrationState,
            ): void {
                $lockedSource = Node::query()->lockForUpdate()->findOrFail($sourceNode->id);
                $lockedTarget = Node::query()->lockForUpdate()->findOrFail($targetNode->id);
                Service::query()->lockForUpdate()->findOrFail($service->id)->update([
                    'node_id' => $oldNodeId,
                    'status' => 'provisioning',
                ]);
                ContainerDeployment::query()->lockForUpdate()->findOrFail($deployment->id)->update([
                    'node_id' => $oldNodeId,
                    'status' => 'deploying',
                    ...$oldMigrationState,
                ]);
                $lockedSource->update([
                    'container_count' => (int) $lockedSource->container_count + 1,
                ]);
                $lockedTarget->update([
                    'container_count' => max(0, (int) $lockedTarget->container_count - 1),
                ]);
            });
        } else {
            $service->update(['node_id' => $oldNodeId, 'status' => 'provisioning']);
            $deployment->update(['node_id' => $oldNodeId, 'status' => 'deploying']);
        }

        if ($targetTouched) {
            try {
                $this->bundleService->stopAndRemoveTarget($targetSsh, $deployment, true, $volumeNames);
            } catch (Throwable $targetCleanupError) {
                Log::warning('Failed to remove incomplete target migration copy', [
                    'service_id' => $service->id,
                    'error' => $targetCleanupError->getMessage(),
                ]);
            }
        }

        if ($sourceStopped) {
            $sourceService = $service->fresh(['containerDeployment.node', 'containerDeployment.domains']);
            $sourceDeployment = $sourceService->containerDeployment;
            $this->deploymentService->ensureComposeFileExists($sourceSsh, $sourceDeployment);
            $this->deploymentService->startComposeStack($sourceSsh, $sourceService, $sourceDeployment);
            $this->waitForTargetReadiness($sourceSsh, $sourceService, $sourceDeployment);
            if ($cutoverCommitted) {
                $this->deploymentService->rebindDeploymentDomainsStrict($sourceService, $sourceDeployment);
            }
        }

        $service->update(['status' => $oldServiceStatus]);
        $deployment->update(['status' => $oldDeploymentStatus]);
    }

    private function verifyPublicDomains(ContainerDeployment $deployment): int
    {
        $domains = $deployment->domains()
            ->whereIn('status', ['active', 'pending'])
            ->get();
        $attempts = max(1, (int) config('containers.migration.public_verify_attempts', 5));
        $delay = max(1, (int) config('containers.migration.public_verify_delay_seconds', 3));
        $verifiedCount = 0;
        foreach ($domains as $domain) {
            if (str_contains($domain->domain, '*')) {
                continue;
            }
            $verified = false;
            $lastError = null;
            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                try {
                    $response = Http::timeout(10)
                        ->withHeaders(['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'])
                        ->get('https://'.$domain->domain.'/?__talksasa_migration='.bin2hex(random_bytes(8)));
                    if ($response->status() >= 100 && $response->status() < 500) {
                        $verified = true;
                        break;
                    }
                    $lastError = 'HTTP '.$response->status();
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                }
                if ($attempt < $attempts - 1) {
                    sleep($delay);
                }
            }
            if (! $verified) {
                throw new Exception(
                    "Domain {$domain->domain} did not verify after target cutover"
                    .($lastError ? ': '.$lastError : '.')
                );
            }
            $verifiedCount++;
        }

        return $verifiedCount;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        Service $service,
        ContainerDeployment $deployment,
        string $event,
        array $payload,
    ): void {
        ContainerDeploymentEvent::create([
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'event' => $event,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }
}
