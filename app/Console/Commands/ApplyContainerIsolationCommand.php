<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentEventRecorder;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerIsolationPolicy;
use App\Services\Provisioning\ContainerStackNetworkAllocator;
use App\Services\Provisioning\PlatformAppsDomainService;
use App\Services\SSH\SSHService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Move stacks that still share talksasa-net onto their own network.
 *
 * New deploys render isolated; nothing else changes a running stack's layout,
 * because a re-render triggered by auto-restart at three in the morning must
 * not be the moment a tenant's containers move. This is the one path that
 * does it on purpose, one stack at a time, watched by an operator.
 *
 * Two things it deliberately does not do, for the same reasons the WordPress
 * database-host repair does not. It never runs two stacks on one node at
 * once: each one recreates its containers, and a node recreating a dozen at
 * once is its own outage. And it does not trust the recreate's own verdict:
 * every stack is inspected afterwards, port by port and network by network,
 * and a stack that does not check out is put back exactly as it was.
 */
class ApplyContainerIsolationCommand extends Command
{
    public const EVENT_APPLIED = 'isolation_applied';

    public const EVENT_ROLLED_BACK = 'isolation_rolled_back';

    private const LOCK = 'container-isolation-rollout';

    private const READINESS_TIMEOUT = 180;

    protected $signature = 'containers:apply-isolation
        {--node= : Only stacks on this node id}
        {--service=* : Only these service ids}
        {--limit= : Stop after this many stacks}
        {--dry-run : List what would change and exit}
        {--continue-on-failure : Keep going after a stack fails}
        {--skip-platform-domain : Do not attach platform hostnames}
        {--force : Do not ask for confirmation}';

    protected $description = 'Move running stacks that still share talksasa-net onto their own loopback-bound, hardened network';

    public function __construct(
        private ContainerDeploymentService $deployments,
        private ContainerIsolationPolicy $policy,
        private ContainerStackNetworkAllocator $subnets,
        private PlatformAppsDomainService $platformHostnames,
        private ContainerDeploymentEventRecorder $events,
        private ?Closure $sshFactory = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $targets = $this->collectTargets();

        if ($targets === []) {
            $this->info('Every running stack is already on its own network.');

            return self::SUCCESS;
        }

        $this->table(
            ['Service', 'Stack', 'Node', 'Template'],
            array_map(static fn (array $row): array => [
                $row['service_id'],
                $row['container_name'],
                $row['node'],
                $row['slug'] ?? '-',
            ], $targets),
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info(count($targets).' stack(s) would be moved. Nothing was modified.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Each stack is recreated on its own network with its ports bound to loopback.');
        $this->line('Data volumes are kept. Expect ten to sixty seconds of downtime per stack.');

        if (! $this->option('force') && ! $this->confirm('Move '.count($targets).' stack(s) now?', false)) {
            $this->info('Nothing was modified.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            $this->error('Another isolation rollout is already running.');

            return self::FAILURE;
        }

        try {
            return $this->applyAll($targets);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    private function applyAll(array $targets): int
    {
        $moved = 0;
        $failed = [];

        foreach ($targets as $index => $target) {
            $position = ($index + 1).'/'.count($targets);
            $this->line("[{$position}] Service {$target['service_id']} {$target['container_name']} on {$target['node']}");

            $outcome = $this->applyOne($target);

            if ($outcome['ok']) {
                $moved++;
                $this->info('      '.$outcome['message']);

                continue;
            }

            $failed[] = $target['service_id'];
            $this->error('      '.$outcome['message']);

            if (! $this->option('continue-on-failure')) {
                $this->newLine();
                $this->error('Stopping here rather than marching through the rest of the fleet.');
                $this->line('The stack was put back on its previous layout. Re-run with --continue-on-failure once you know why it failed.');

                break;
            }
        }

        $this->newLine();
        $this->line('Moved: '.$moved);
        $this->line('Failed: '.count($failed));

        if ($failed !== []) {
            $this->line('Still on the shared bridge: service '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array{ok: bool, message: string}
     */
    private function applyOne(array $target): array
    {
        /** @var ContainerDeployment $deployment */
        $deployment = $target['deployment']->fresh(['service.product.containerTemplate', 'node']);
        $service = $deployment->service;
        $node = $deployment->node;
        if (! $service || ! $node) {
            return ['ok' => false, 'message' => 'deployment has lost its service or node'];
        }

        $containerName = (string) $deployment->container_name;
        $before = null;

        try {
            $ssh = $this->sshForNode($node);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'node unreachable: '.$e->getMessage()];
        }

        try {
            $before = $this->deployments->liveComposeYaml($ssh, $deployment);
            if ($before === '') {
                throw new \RuntimeException('docker-compose.yml is missing on the node and the panel has no copy.');
            }

            $subnet = $this->subnets->ensureFor($deployment);
            $slug = $target['slug'];
            $patched = $this->policy->applyToYaml(
                $before,
                ContainerIsolationPolicy::appServiceKey($before, $containerName),
                $containerName,
                $subnet,
                $slug,
            );

            $ssh->upload($patched, $this->composePath($containerName));
            $deployment->update(['docker_compose_content' => $patched]);

            $this->deployments->startComposeStack($ssh, $service, $deployment);
            $this->deployments->waitForContainerRunning($ssh, $containerName, self::READINESS_TIMEOUT);

            $problems = $this->verify($ssh, $deployment->fresh(), $slug);
            if ($problems !== []) {
                throw new \RuntimeException('verification failed: '.implode('; ', $problems));
            }

            if (! $this->option('skip-platform-domain')) {
                $this->platformHostnames->attach(
                    $service->fresh(['user', 'containerDeployment.node', 'containerDeployment.domains'])
                );
            }

            $network = ContainerIsolationPolicy::stackNetworkName($containerName);
            $this->events->record($service, $deployment, self::EVENT_APPLIED, [
                'network' => $network,
                'subnet' => $subnet,
                'previous_layout' => 'shared-bridge',
            ]);

            return $this->record($target, true, "now on {$network} ({$subnet}), ports on loopback");
        } catch (\Throwable $e) {
            $this->rollback($ssh, $service, $deployment, $before, $e);

            return $this->record($target, false, mb_substr(trim($e->getMessage()), 0, 300));
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Put the stack back on the layout it had. The subnet stays reserved on
     * the row so a retry lands on the same block.
     */
    private function rollback(SSHService $ssh, Service $service, ContainerDeployment $deployment, ?string $before, \Throwable $cause): void
    {
        $restored = false;

        if ($before !== null && $before !== '') {
            try {
                $ssh->upload($before, $this->composePath((string) $deployment->container_name));
                $deployment->update(['docker_compose_content' => $before]);
                $this->deployments->startComposeStack($ssh, $service, $deployment);
                $this->deployments->waitForContainerRunning($ssh, (string) $deployment->container_name, self::READINESS_TIMEOUT);
                $restored = true;
            } catch (\Throwable $rollbackError) {
                Log::critical('Isolation rollback could not restart the stack on its previous layout', [
                    'service_id' => $service->id,
                    'deployment_id' => $deployment->id,
                    'cause' => $cause->getMessage(),
                    'rollback_error' => $rollbackError->getMessage(),
                ]);
                $this->error('      rollback failed as well: '.$rollbackError->getMessage());
            }
        }

        $this->events->record($service, $deployment, self::EVENT_ROLLED_BACK, [
            'error' => mb_substr($cause->getMessage(), 0, 500),
            'restored' => $restored,
        ]);
    }

    /**
     * What the node actually runs, container by container. Compose reporting
     * success says the file parsed; this says the tenant boundary is there.
     *
     * @return list<string>
     */
    private function verify(SSHService $ssh, ContainerDeployment $deployment, ?string $slug): array
    {
        $containerName = (string) $deployment->container_name;
        $problems = [];

        $after = $this->deployments->liveComposeYaml($ssh, $deployment);
        if (! $this->policy->isCurrent($after)) {
            $problems[] = 'compose file on the node is not on the current isolation policy';
        }

        $names = array_values(array_filter(array_map('trim', explode("\n", (string) $ssh->exec(
            'docker ps -a --filter label=com.docker.compose.project='.escapeshellarg($containerName)." --format '{{.Names}}'",
            20
        )))));
        if ($names === []) {
            return array_merge($problems, ['no containers found for the stack']);
        }

        $options = $this->policy->options();
        $stackNetwork = ContainerIsolationPolicy::stackNetworkName($containerName);
        $sharedAllowed = $options->joinsSharedNetwork($slug);
        $requiredDrops = $options->forSlug($slug)['cap_drop'];
        $pidsLimit = $options->forSlug($slug)['pids_limit'];

        foreach ($names as $name) {
            $raw = (string) $ssh->exec(
                "docker inspect --format '{{json .NetworkSettings.Ports}}||{{json .NetworkSettings.Networks}}||{{json .HostConfig.SecurityOpt}}||{{json .HostConfig.CapDrop}}||{{.HostConfig.PidsLimit}}' "
                .escapeshellarg($name),
                20
            );
            $parts = explode('||', trim($raw));
            if (count($parts) !== 5) {
                $problems[] = "{$name}: could not be inspected";

                continue;
            }
            [$portsJson, $networksJson, $securityJson, $capDropJson, $pids] = $parts;

            foreach ((array) json_decode($portsJson, true) as $port => $bindings) {
                foreach ((array) $bindings as $binding) {
                    $hostIp = (string) ($binding['HostIp'] ?? '');
                    if ($hostIp !== $options->publishBindAddress) {
                        $problems[] = "{$name}: {$port} is published on ".($hostIp === '' ? 'all interfaces' : $hostIp);
                    }
                }
            }

            $networks = array_keys((array) json_decode($networksJson, true));
            if (! in_array($stackNetwork, $networks, true)) {
                $problems[] = "{$name}: not on {$stackNetwork}";
            }
            if (in_array(ContainerIsolationPolicy::SHARED_NETWORK_NAME, $networks, true)
                && ! ($sharedAllowed && $name === $containerName)) {
                $problems[] = "{$name}: still attached to ".ContainerIsolationPolicy::SHARED_NETWORK_NAME;
            }

            if ($options->noNewPrivileges
                && ! in_array(ContainerIsolationPolicy::NO_NEW_PRIVILEGES, (array) json_decode($securityJson, true), true)) {
                $problems[] = "{$name}: no-new-privileges is not set";
            }

            $drops = array_map('strtoupper', (array) json_decode($capDropJson, true));
            if (! in_array('ALL', $drops, true)) {
                $missing = array_diff($requiredDrops, $drops);
                if ($missing !== []) {
                    $problems[] = "{$name}: still holds ".implode(', ', $missing);
                }
            }

            if ($pidsLimit > 0 && (! is_numeric(trim($pids)) || (int) trim($pids) <= 0)) {
                $problems[] = "{$name}: no pids limit";
            }
        }

        return $problems;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectTargets(): array
    {
        $nodeId = $this->option('node') !== null ? (int) $this->option('node') : null;
        $only = array_values(array_filter(array_map('intval', (array) $this->option('service'))));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $query = ContainerDeployment::query()
            ->where('status', 'running')
            ->whereHas('service', fn ($q) => $q->where('status', ServiceStatus::Active))
            ->whereHas('node', fn ($q) => $q->where('is_active', true)->where('type', 'container_host'))
            ->with(['service.product.containerTemplate', 'node'])
            ->orderBy('node_id')
            ->orderBy('id');
        if ($nodeId !== null) {
            $query->where('node_id', $nodeId);
        }
        if ($only !== []) {
            $query->whereIn('service_id', $only);
        }

        $this->info('Reading docker-compose.yml on every running stack. Nothing is modified yet.');

        $targets = [];
        foreach ($query->get()->groupBy('node_id') as $deployments) {
            if ($limit !== null && count($targets) >= $limit) {
                break;
            }

            /** @var Node $node */
            $node = $deployments->first()->node;

            try {
                $ssh = $this->sshForNode($node);
            } catch (\Throwable $e) {
                $this->warn("Node {$node->hostname} is unreachable, so its stacks were skipped: {$e->getMessage()}");

                continue;
            }

            try {
                foreach ($deployments as $deployment) {
                    if ($limit !== null && count($targets) >= $limit) {
                        break;
                    }

                    $yaml = $this->deployments->liveComposeYaml($ssh, $deployment);
                    if ($yaml === '') {
                        $this->warn("Service {$deployment->service_id} {$deployment->container_name}: no compose file on {$node->hostname}, skipped.");

                        continue;
                    }
                    if ($this->policy->isCurrent($yaml)) {
                        continue;
                    }

                    $targets[] = [
                        'service_id' => $deployment->service_id,
                        'container_name' => $deployment->container_name,
                        'node' => $node->hostname,
                        'slug' => $deployment->service?->effectiveContainerTemplate()?->slug,
                        'deployment' => $deployment,
                    ];
                }
            } finally {
                $ssh->disconnect();
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array{ok: bool, message: string}
     */
    private function record(array $target, bool $ok, string $message): array
    {
        Log::info('Container isolation rollout', [
            'service_id' => $target['service_id'],
            'container' => $target['container_name'],
            'node' => $target['node'],
            'ok' => $ok,
            'message' => $message,
        ]);

        return ['ok' => $ok, 'message' => $message];
    }

    private function composePath(string $containerName): string
    {
        return ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$containerName.'/docker-compose.yml';
    }

    private function sshForNode(Node $node): SSHService
    {
        return $this->sshFactory ? ($this->sshFactory)($node) : SSHService::forNode($node);
    }
}
