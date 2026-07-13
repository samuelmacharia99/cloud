<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * After operators manually move workloads off a node, rescan the destination
 * and update platform records so the old node can be deleted safely.
 */
class NodeServiceRelocationService
{
    /** @var callable(Node): SSHService|null */
    private $sshFactory = null;

    /** @var callable(Node): DirectAdminService|null */
    private $directAdminFactory = null;

    public function __construct(
        private ?ContainerRuntimeInspector $inspector = null,
        private ?NginxProxyService $nginx = null,
    ) {
        $this->inspector ??= new ContainerRuntimeInspector;
        $this->nginx ??= new NginxProxyService;
    }

    /**
     * @param  callable(Node): SSHService  $factory
     */
    public function usingSshFactory(callable $factory): self
    {
        $this->sshFactory = $factory;

        return $this;
    }

    /**
     * @param  callable(Node): DirectAdminService  $factory
     */
    public function usingDirectAdminFactory(callable $factory): self
    {
        $this->directAdminFactory = $factory;

        return $this;
    }

    private function sshFor(Node $node): SSHService
    {
        if ($this->sshFactory) {
            return ($this->sshFactory)($node);
        }

        return SSHService::forNode($node);
    }

    private function directAdminFor(Node $node): DirectAdminService
    {
        if ($this->directAdminFactory) {
            return ($this->directAdminFactory)($node);
        }

        return new DirectAdminService($node);
    }

    /**
     * @return Collection<int, Node>
     */
    public function candidateTargets(Node $source): Collection
    {
        return Node::query()
            ->where('type', $source->type)
            ->where('is_active', true)
            ->where('id', '!=', $source->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Remaining platform records that still point at this node.
     */
    public function remainingCount(Node $node): int
    {
        $count = $node->servicesOnNodeQuery()->count();

        if ($node->type === 'directadmin') {
            $count += $node->assignedResellers()->count();
        }

        return $count;
    }

    /**
     * Clear platform node links so the node record can be deleted.
     * Does not touch DirectAdmin accounts, containers, or files on the server.
     *
     * @return array{services: int, resellers: int, deployments: int}
     */
    public function detachAllFromNode(Node $node): array
    {
        $servicesDetached = 0;
        $deploymentsDetached = 0;

        $services = $node->servicesOnNodeQuery()
            ->with('containerDeployment')
            ->get();

        foreach ($services as $service) {
            $deployment = $service->containerDeployment;
            if ($deployment && (int) $deployment->node_id === (int) $node->id) {
                $deployment->update(['node_id' => null]);
                $deploymentsDetached++;
            }

            if ((int) $service->node_id === (int) $node->id) {
                $service->update(['node_id' => null]);
            }

            $servicesDetached++;
        }

        $resellersDetached = 0;
        if ($node->type === 'directadmin') {
            $resellersDetached = User::query()
                ->where('is_reseller', true)
                ->where('reseller_node_id', $node->id)
                ->update(['reseller_node_id' => null]);
        }

        Log::info('Detached platform records from node before delete', [
            'node_id' => $node->id,
            'services' => $servicesDetached,
            'deployments' => $deploymentsDetached,
            'resellers' => $resellersDetached,
        ]);

        return [
            'services' => $servicesDetached,
            'resellers' => $resellersDetached,
            'deployments' => $deploymentsDetached,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scan(Node $source, Node $target): array
    {
        $this->assertCompatible($source, $target);

        $services = $source->servicesOnNodeQuery()
            ->with(['user', 'containerDeployment'])
            ->orderBy('id')
            ->get();

        return match ($source->type) {
            'container_host' => $this->scanContainers($services, $target),
            'directadmin' => $this->scanDirectAdmin($services, $source, $target),
            default => $services->map(fn (Service $service) => $this->genericServiceRow($service))->all(),
        };
    }

    /**
     * Update records for workloads found on the destination.
     *
     * @return array{updated: list<string>, skipped: list<string>, failed: list<array{key: string, error: string}>, scan: list<array<string, mixed>>}
     */
    public function apply(Node $source, Node $target, bool $onlyFound = true): array
    {
        $this->assertCompatible($source, $target);

        $scan = $this->scan($source, $target);
        $updated = [];
        $skipped = [];
        $failed = [];

        foreach ($scan as $row) {
            $key = $this->rowKey($row);

            if ($onlyFound && ! ($row['found'] ?? false)) {
                $skipped[] = $key;

                continue;
            }

            try {
                if (($row['kind'] ?? 'service') === 'reseller') {
                    $this->relocateReseller((int) $row['reseller_id'], $target);
                } elseif ($source->type === 'container_host') {
                    $service = Service::with('containerDeployment.domains')->findOrFail((int) $row['service_id']);
                    $this->relocateContainerService($service, $target, $row);
                } else {
                    Service::query()->whereKey((int) $row['service_id'])->update(['node_id' => $target->id]);
                }

                $updated[] = $key;
            } catch (\Throwable $e) {
                Log::error('Failed to relocate record during node delete prep', [
                    'key' => $key,
                    'source_node_id' => $source->id,
                    'target_node_id' => $target->id,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'scan' => $scan,
        ];
    }

    private function assertCompatible(Node $source, Node $target): void
    {
        if ($source->id === $target->id) {
            throw new Exception('Source and destination nodes must be different.');
        }

        if ($source->type !== $target->type) {
            throw new Exception('Destination node must be the same type as the node being deleted.');
        }

        if (! $target->is_active) {
            throw new Exception('Destination node is not active.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowKey(array $row): string
    {
        if (($row['kind'] ?? 'service') === 'reseller') {
            return 'reseller:'.($row['reseller_id'] ?? 0);
        }

        return 'service:'.($row['service_id'] ?? 0);
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    private function scanContainers(Collection $services, Node $target): array
    {
        $ssh = $this->sshFor($target);

        try {
            $rows = [];

            foreach ($services as $service) {
                $deployment = $service->containerDeployment;
                $containerName = $deployment?->container_name;

                if (! $deployment || ! $containerName) {
                    $rows[] = [
                        'kind' => 'service',
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                        'customer' => $service->user?->email,
                        'container_name' => $containerName,
                        'da_username' => null,
                        'deployment_id' => $deployment?->id,
                        'found' => false,
                        'running' => false,
                        'published_port' => null,
                        'message' => 'No container deployment / name to scan for.',
                    ];

                    continue;
                }

                try {
                    $inspect = $this->inspector->inspect($ssh, $containerName);
                    $found = ! ($inspect['missing'] ?? true);
                    $publishedPort = $found ? $this->readPublishedHostPort($ssh, $containerName) : null;

                    $rows[] = [
                        'kind' => 'service',
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                        'customer' => $service->user?->email,
                        'container_name' => $containerName,
                        'da_username' => null,
                        'deployment_id' => $deployment->id,
                        'found' => $found,
                        'running' => (bool) ($inspect['running'] ?? false),
                        'published_port' => $publishedPort,
                        'message' => $found
                            ? (($inspect['running'] ?? false) ? 'Found running on destination.' : 'Found on destination (not running).')
                            : 'Container not found on destination.',
                    ];
                } catch (\Throwable $e) {
                    $rows[] = [
                        'kind' => 'service',
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                        'customer' => $service->user?->email,
                        'container_name' => $containerName,
                        'da_username' => null,
                        'deployment_id' => $deployment->id,
                        'found' => false,
                        'running' => false,
                        'published_port' => null,
                        'message' => 'Scan failed: '.$e->getMessage(),
                    ];
                }
            }

            return $rows;
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    private function scanDirectAdmin(Collection $services, Node $source, Node $target): array
    {
        $da = $this->directAdminFor($target);

        if (! $da->isConfigured()) {
            throw new Exception('Destination DirectAdmin API is not configured. Add API URL and login key on the destination node.');
        }

        $rows = [];

        foreach ($services as $service) {
            $username = $this->resolveHostingUsername($service);

            if ($username === null) {
                $rows[] = [
                    'kind' => 'service',
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'customer' => $service->user?->email,
                    'container_name' => null,
                    'da_username' => null,
                    'deployment_id' => null,
                    'found' => false,
                    'running' => false,
                    'published_port' => null,
                    'message' => 'No DirectAdmin username on this service (external_reference / service_meta.username).',
                ];

                continue;
            }

            try {
                $found = $da->accountExists($username);
                $live = $found ? $da->getAccountLiveStatus($username) : null;
                $active = ($live['live_status'] ?? null) === 'active';

                $rows[] = [
                    'kind' => 'service',
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'customer' => $service->user?->email,
                    'container_name' => null,
                    'da_username' => $username,
                    'deployment_id' => null,
                    'found' => $found,
                    'running' => $active,
                    'published_port' => null,
                    'message' => $found
                        ? ($live['label'] ?? 'Found on destination DirectAdmin.')
                        : 'Account not found on destination DirectAdmin.',
                ];
            } catch (\Throwable $e) {
                $rows[] = [
                    'kind' => 'service',
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'customer' => $service->user?->email,
                    'container_name' => null,
                    'da_username' => $username,
                    'deployment_id' => null,
                    'found' => false,
                    'running' => false,
                    'published_port' => null,
                    'message' => 'Scan failed: '.$e->getMessage(),
                ];
            }
        }

        $resellers = User::query()
            ->where('is_reseller', true)
            ->where('reseller_node_id', $source->id)
            ->orderBy('id')
            ->get();

        foreach ($resellers as $reseller) {
            $username = filled($reseller->directadmin_username)
                ? strtolower(trim((string) $reseller->directadmin_username))
                : null;

            if ($username === null) {
                $rows[] = [
                    'kind' => 'reseller',
                    'reseller_id' => $reseller->id,
                    'service_id' => null,
                    'service_name' => $reseller->company ?: $reseller->name ?: $reseller->email,
                    'customer' => $reseller->email,
                    'container_name' => null,
                    'da_username' => null,
                    'deployment_id' => null,
                    'found' => false,
                    'running' => false,
                    'published_port' => null,
                    'message' => 'Reseller has no DirectAdmin username to verify on destination.',
                ];

                continue;
            }

            try {
                $found = $da->accountExists($username);
                $live = $found ? $da->getAccountLiveStatus($username) : null;

                $rows[] = [
                    'kind' => 'reseller',
                    'reseller_id' => $reseller->id,
                    'service_id' => null,
                    'service_name' => $reseller->company ?: $reseller->name ?: $reseller->email,
                    'customer' => $reseller->email,
                    'container_name' => null,
                    'da_username' => $username,
                    'deployment_id' => null,
                    'found' => $found,
                    'running' => ($live['live_status'] ?? null) === 'active',
                    'published_port' => null,
                    'message' => $found
                        ? ('Reseller account: '.($live['label'] ?? 'Found on destination.'))
                        : 'Reseller DirectAdmin account not found on destination.',
                ];
            } catch (\Throwable $e) {
                $rows[] = [
                    'kind' => 'reseller',
                    'reseller_id' => $reseller->id,
                    'service_id' => null,
                    'service_name' => $reseller->company ?: $reseller->name ?: $reseller->email,
                    'customer' => $reseller->email,
                    'container_name' => null,
                    'da_username' => $username,
                    'deployment_id' => null,
                    'found' => false,
                    'running' => false,
                    'published_port' => null,
                    'message' => 'Scan failed: '.$e->getMessage(),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function genericServiceRow(Service $service): array
    {
        return [
            'kind' => 'service',
            'service_id' => $service->id,
            'service_name' => $service->name,
            'customer' => $service->user?->email,
            'container_name' => null,
            'da_username' => null,
            'deployment_id' => null,
            'found' => true,
            'running' => false,
            'published_port' => null,
            'message' => 'Will reassign service node_id to the selected destination (no remote rescan for this node type).',
        ];
    }

    private function resolveHostingUsername(Service $service): ?string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $creds = is_array($service->credentials) ? $service->credentials : [];
        $username = $meta['username']
            ?? $service->external_reference
            ?? ($creds['username'] ?? null);

        return filled($username) ? strtolower(trim((string) $username)) : null;
    }

    private function relocateReseller(int $resellerId, Node $target): void
    {
        User::query()
            ->whereKey($resellerId)
            ->where('is_reseller', true)
            ->update(['reseller_node_id' => $target->id]);
    }

    /**
     * @param  array<string, mixed>  $scanRow
     */
    private function relocateContainerService(Service $service, Node $target, array $scanRow): void
    {
        $deployment = $service->containerDeployment;
        if (! $deployment) {
            throw new Exception("Service {$service->id} has no container deployment.");
        }

        $deployment->loadMissing('domains');
        $this->removeProxyConfigsBestEffort($deployment);

        $oldNodeId = $deployment->node_id;
        $publishedPort = isset($scanRow['published_port']) && is_numeric($scanRow['published_port'])
            ? (int) $scanRow['published_port']
            : null;

        DB::transaction(function () use ($service, $deployment, $target, $oldNodeId, $publishedPort, $scanRow) {
            $updates = [
                'node_id' => $target->id,
                'migrated_from_node_id' => $oldNodeId,
                'migrated_at' => now(),
                'migration_reason' => 'node_delete_rescan',
                'status' => ($scanRow['running'] ?? false) ? 'running' : 'stopped',
                'last_status_check_at' => now(),
                'last_status_check_output' => $scanRow['message'] ?? 'Relocated via node delete rescan',
            ];

            if ($publishedPort !== null) {
                $conflict = ContainerDeployment::query()
                    ->where('node_id', $target->id)
                    ->where('assigned_port', $publishedPort)
                    ->where('id', '!=', $deployment->id)
                    ->exists();

                if ($conflict) {
                    throw new Exception(
                        "Port {$publishedPort} is already assigned on destination for another deployment."
                    );
                }

                $updates['assigned_port'] = $publishedPort;
            }

            $deployment->forceFill($updates)->save();
            $service->update(['node_id' => $target->id]);
        });

        $this->bindDomainsBestEffort($deployment->fresh(['domains', 'node']));
    }

    private function removeProxyConfigsBestEffort(ContainerDeployment $deployment): void
    {
        foreach ($deployment->domains as $domain) {
            try {
                $domain->setRelation('deployment', $deployment);
                $this->nginx->removeProxyConfig($domain);
            } catch (\Throwable $e) {
                Log::warning('Failed to remove old nginx config during relocation', [
                    'domain' => $domain->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function bindDomainsBestEffort(ContainerDeployment $deployment): void
    {
        foreach ($deployment->domains as $domain) {
            if (! in_array($domain->status, ['active', 'pending'], true)) {
                continue;
            }

            try {
                $this->nginx->bind($domain->fresh(['deployment.node']));
            } catch (\Throwable $e) {
                Log::warning('Failed to rebind domain after relocation', [
                    'domain' => $domain->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function readPublishedHostPort(SSHService $ssh, string $containerName): ?int
    {
        $safeName = escapeshellarg($containerName);
        $json = trim($ssh->exec(
            "docker inspect --type container --format '{{json .NetworkSettings.Ports}}' {$safeName} 2>/dev/null || echo ''",
            10
        ));

        if ($json === '' || $json === 'null') {
            return null;
        }

        $ports = json_decode($json, true);
        if (! is_array($ports)) {
            return null;
        }

        foreach ($ports as $bindings) {
            if (! is_array($bindings)) {
                continue;
            }
            foreach ($bindings as $binding) {
                $hostPort = $binding['HostPort'] ?? null;
                if (is_numeric($hostPort)) {
                    return (int) $hostPort;
                }
            }
        }

        return null;
    }
}
