<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * What a container host is really publishing, and which of it the platform
 * knows about. A container that outlives its deployment row still holds its
 * host port, and the allocator only reads the platform's own records, so that
 * port is handed to the next deploy and Docker refuses to bind it.
 *
 * Read-only by design: the report names strays, it never removes them.
 */
class ContainerNodePortAuditService
{
    public function __construct(
        private ContainerDeploymentService $deployments,
        private ComposeProjectStateProbe $probe,
    ) {}

    /**
     * @return array{
     *     supported: bool,
     *     reachable: bool,
     *     message: ?string,
     *     listening_ports: list<int>,
     *     claimed: list<array<string, mixed>>,
     *     orphans: list<array<string, mixed>>,
     *     unexplained_ports: list<int>
     * }
     */
    public function audit(Node $node, ?SSHService $ssh = null): array
    {
        $empty = [
            'supported' => true,
            'reachable' => false,
            'message' => null,
            'listening_ports' => [],
            'claimed' => [],
            'orphans' => [],
            'unexplained_ports' => [],
        ];

        if ($node->type !== 'container_host') {
            return array_merge($empty, ['supported' => false, 'message' => 'Port audits are for container hosts.']);
        }

        $owned = $ssh === null;
        try {
            $ssh ??= SSHService::forNode($node);
            $listening = $this->deployments->nodeListeningPorts($ssh);
            $projects = $this->probe->probeNode($ssh);
        } catch (\Throwable $e) {
            Log::warning('Container node port audit could not reach the node', [
                'node_id' => $node->id,
                'error' => $e->getMessage(),
            ]);

            return array_merge($empty, ['message' => 'Could not reach the node: '.$e->getMessage()]);
        } finally {
            if ($owned && $ssh !== null) {
                $ssh->disconnect();
            }
        }

        $known = ContainerDeployment::query()
            ->where('node_id', $node->id)
            ->whereNotNull('container_name')
            ->get(['id', 'service_id', 'container_name', 'assigned_port', 'status'])
            ->keyBy(fn (ContainerDeployment $row): string => (string) $row->container_name);

        $claimed = [];
        $orphans = [];
        $explained = [];

        foreach ($projects as $project => $containers) {
            $deployment = $known->get((string) $project);
            $ports = [];
            $states = [];
            foreach ($containers as $container) {
                $ports = array_merge($ports, $this->deployments->parseListeningPorts((string) ($container['ports'] ?? '')));
                $states[] = (string) ($container['state'] ?? '');
            }
            $ports = array_values(array_unique($ports));
            $running = in_array('running', array_map('strtolower', $states), true);

            $row = [
                'project' => (string) $project,
                'ports' => $ports,
                'running' => $running,
                'containers' => count($containers),
                'status' => (string) ($containers[array_key_first($containers)]['status'] ?? ''),
            ];

            if ($deployment) {
                $row['service_id'] = (int) $deployment->service_id;
                $row['deployment_status'] = (string) $deployment->status;
                $row['assigned_port'] = (int) $deployment->assigned_port;
                $claimed[] = $row;
            } else {
                $orphans[] = $row;
            }

            $explained = array_merge($explained, $ports);
        }

        // A port the kernel reports with no container behind it: a host process,
        // a tunnel, or something else outside Docker entirely.
        $unexplained = array_values(array_diff($listening, array_unique($explained)));
        sort($unexplained);

        return [
            'supported' => true,
            'reachable' => true,
            'message' => null,
            'listening_ports' => $listening,
            'claimed' => $claimed,
            'orphans' => $orphans,
            'unexplained_ports' => $unexplained,
        ];
    }
}
