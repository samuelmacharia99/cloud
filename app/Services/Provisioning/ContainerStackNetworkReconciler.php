<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Before a stack comes up, make the node agree with the database about its
 * network. A service that changed template runs under a new container name
 * while its old stack still holds the subnet the row carries; a failed
 * attempt can leave an empty network behind; a network nobody recorded can
 * sit on the block. Docker answers all three with "Pool overlaps with other
 * one on this address space", so they are resolved here instead.
 */
class ContainerStackNetworkReconciler
{
    public function __construct(private readonly ContainerStackNetworkAllocator $allocator) {}

    public function liveNetworksCommand(): string
    {
        return 'docker network ls -q 2>/dev/null | xargs -r docker network inspect --format '
            .escapeshellarg('{{.Name}}|{{range .IPAM.Config}}{{.Subnet}} {{end}}|{{len .Containers}}')
            .' 2>/dev/null; true';
    }

    /**
     * @return list<array{name: string, subnets: list<string>, containers: int}>
     */
    public function parseLiveNetworks(string $output): array
    {
        $networks = [];
        foreach (explode("\n", $output) as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) !== 3 || $parts[0] === '') {
                continue;
            }
            $subnets = array_values(array_filter(array_map('trim', explode(' ', $parts[1]))));
            $networks[] = ['name' => $parts[0], 'subnets' => $subnets, 'containers' => (int) $parts[2]];
        }

        return $networks;
    }

    /**
     * @param  callable(string): void  $tearDownStack  receives a stack path to bring down (files kept)
     * @return array{subnet: ?string, actions: list<string>}
     */
    public function reconcile(
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $containerName,
        string $previousContainerName,
        callable $tearDownStack,
    ): array {
        $actions = [];
        $previousContainerName = trim($previousContainerName);

        if ($previousContainerName !== '' && $previousContainerName !== $containerName && $this->isSafeName($previousContainerName)) {
            $stalePath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$previousContainerName;
            $tearDownStack($stalePath);
            $this->removeNetwork($ssh, $previousContainerName.'-net');
            $actions[] = 'retired previous stack '.$previousContainerName;
        }

        $live = [];
        try {
            $live = $this->parseLiveNetworks((string) $ssh->exec($this->liveNetworksCommand(), 60));
        } catch (\Throwable $e) {
            Log::warning('Could not list Docker networks before deploy', ['container' => $containerName, 'error' => $e->getMessage()]);
        }

        $ownNetwork = $containerName.'-net';
        $subnet = trim((string) ($deployment->network_subnet ?? ''));
        $servicePrefix = $this->servicePrefix($containerName);

        $conflicting = [];
        foreach ($live as $network) {
            if ($network['name'] === $ownNetwork || $subnet === '' || ! in_array($subnet, $network['subnets'], true)) {
                continue;
            }
            $conflicting[] = $network;
        }

        $unresolved = false;
        foreach ($conflicting as $network) {
            $name = $network['name'];
            if ($network['containers'] === 0) {
                $this->removeNetwork($ssh, $name);
                $actions[] = 'removed empty network '.$name.' on '.$subnet;

                continue;
            }
            if ($servicePrefix !== null && str_starts_with($name, $servicePrefix) && str_ends_with($name, '-net') && $this->isSafeName(substr($name, 0, -4))) {
                $stale = substr($name, 0, -4);
                $tearDownStack(ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$stale);
                $this->removeNetwork($ssh, $name);
                $actions[] = 'retired stale stack '.$stale.' holding '.$subnet;

                continue;
            }
            $unresolved = true;
            $actions[] = 'network '.$name.' belongs to another stack and holds '.$subnet;
        }

        if ($unresolved) {
            $liveUsed = [];
            foreach ($live as $network) {
                foreach ($network['subnets'] as $s) {
                    $liveUsed[] = $s;
                }
            }
            $fresh = $this->allocator->reallocate($deployment, $liveUsed);
            $actions[] = 'moved this stack to '.$fresh;
            $subnet = $fresh;
        }

        return ['subnet' => $subnet !== '' ? $subnet : null, 'actions' => $actions];
    }

    private function removeNetwork(SSHService $ssh, string $network): void
    {
        try {
            $ssh->exec('docker network rm '.escapeshellarg($network).' >/dev/null 2>&1 || true', 30);
        } catch (\Throwable $e) {
            Log::info('Docker network not removed', ['network' => $network, 'error' => $e->getMessage()]);
        }
    }

    /**
     * "user-<uid>-service-<sid>-" from a container name, so stale stacks of
     * the same service can be told apart from another tenant's network.
     */
    public function servicePrefix(string $containerName): ?string
    {
        return preg_match('/^(user-\d+-service-\d+-)/', $containerName, $m) === 1 ? $m[1] : null;
    }

    private function isSafeName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name) === 1;
    }
}
