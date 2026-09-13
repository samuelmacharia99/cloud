<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * Reads and refreshes the per-container state snapshot of a deployment.
 *
 * Read paths never open SSH: the project page overlays the snapshot the
 * metrics tick wrote. Write paths take one docker ps per node (or per stack
 * after an action) and store what they saw; a node that cannot be reached
 * keeps its last known containers and gets an error stamp instead, so the
 * page reports "unknown", never a stale "running".
 */
class StackMemberStateService
{
    /** A snapshot fresher than this is not rewritten when nothing changed. */
    private const REWRITE_AFTER_MINUTES = 10;

    /**
     * @param  (Closure(Node): SSHService)|null  $sshFactory
     */
    public function __construct(
        private ComposeProjectStateProbe $probe,
        private StackMemberResolver $resolver,
        private ?Closure $sshFactory = null,
    ) {}

    public function snapshotFor(ContainerDeployment $deployment): StackMemberSnapshot
    {
        return StackMemberSnapshot::fromDeployment($deployment);
    }

    public function isStale(ContainerDeployment $deployment): bool
    {
        $checkedAt = $this->snapshotFor($deployment)->checkedAt;

        return $checkedAt === null || $checkedAt->lt(now()->subMinutes($this->staleAfterMinutes()));
    }

    /**
     * @param  list<StackMember>  $members
     * @return list<StackMember>
     */
    public function overlay(ContainerDeployment $deployment, array $members): array
    {
        $snapshot = $this->snapshotFor($deployment);
        $stale = $this->isStale($deployment);
        $unknown = $snapshot->checkedAt === null
            || $snapshot->checkedAt->lt(now()->subMinutes($this->unknownAfterMinutes()))
            || (! $snapshot->reachable && $stale);

        return array_map(function (StackMember $member) use ($snapshot, $stale, $unknown): StackMember {
            if ($member->synthesized) {
                return $member;
            }

            return $member->withState($snapshot->stateFor($member->containerName, $member->composeKey, $stale, $unknown));
        }, $members);
    }

    /**
     * One stack, one docker ps.
     */
    public function refresh(ContainerDeployment $deployment, SSHService $ssh): StackMemberSnapshot
    {
        try {
            $rows = $this->probe->probeProject($ssh, (string) $deployment->container_name);
        } catch (\Throwable $e) {
            $this->recordUnreachable($deployment, $e->getMessage());

            return $this->snapshotFor($deployment->fresh());
        }

        return $this->write($deployment, $rows);
    }

    /**
     * Every stack on a node, one docker ps.
     *
     * @param  Collection<int, ContainerDeployment>  $deployments
     * @return array<int, StackMemberSnapshot> keyed by deployment id
     */
    public function refreshNode(SSHService $ssh, Collection $deployments): array
    {
        try {
            $byProject = $this->probe->probeNode($ssh);
        } catch (\Throwable $e) {
            foreach ($deployments as $deployment) {
                $this->recordUnreachable($deployment, $e->getMessage());
            }

            throw $e;
        }

        $snapshots = [];
        foreach ($deployments as $deployment) {
            if ((string) $deployment->status === 'terminated') {
                continue;
            }
            $snapshots[$deployment->id] = $this->write($deployment, $byProject[(string) $deployment->container_name] ?? []);
        }

        return $snapshots;
    }

    /**
     * Opens its own SSH session; for callers that hold none (controllers).
     */
    public function refreshForService(Service $service): StackMemberSnapshot
    {
        $service->loadMissing('containerDeployment.node');
        $deployment = $service->containerDeployment;
        if (! $deployment || ! $deployment->node) {
            throw new \DomainException('This application is not deployed on a host yet.');
        }

        $ssh = $this->ssh($deployment->node);
        try {
            return $this->refresh($deployment, $ssh);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Every deployed stack of a project, grouped per node so each host is
     * asked once. Stops when the time budget runs out.
     *
     * @return array{refreshed: int, failed: int, skipped: int}
     */
    public function refreshProject(CustomerProject $project, int $budgetSeconds = 25): array
    {
        $deadline = time() + max(5, $budgetSeconds);
        $summary = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];

        $deployments = $project->liveApplicationHostingServices()
            ->map(fn (Service $service) => $service->containerDeployment)
            ->filter(fn (?ContainerDeployment $deployment) => $deployment && $deployment->node && (string) $deployment->status !== 'terminated')
            ->groupBy('node_id');

        foreach ($deployments as $nodeDeployments) {
            if (time() >= $deadline) {
                $summary['skipped'] += $nodeDeployments->count();

                continue;
            }

            $node = $nodeDeployments->first()->node;
            try {
                $ssh = $this->ssh($node);
            } catch (\Throwable $e) {
                foreach ($nodeDeployments as $deployment) {
                    $this->recordUnreachable($deployment, $e->getMessage());
                }
                $summary['failed'] += $nodeDeployments->count();

                continue;
            }

            try {
                $summary['refreshed'] += count($this->refreshNode($ssh, $nodeDeployments->values()));
            } catch (\Throwable) {
                $summary['failed'] += $nodeDeployments->count();
            } finally {
                $ssh->disconnect();
            }
        }

        return $summary;
    }

    /**
     * The node could not be asked. Keep what was last seen; stamp the error;
     * do not advance checked_at, so staleness keeps counting from the last
     * real observation.
     */
    public function recordUnreachable(ContainerDeployment $deployment, string $error): void
    {
        $current = $this->snapshotFor($deployment);
        $snapshot = new StackMemberSnapshot(
            $current->containers,
            $current->checkedAt,
            false,
            mb_substr($error, 0, 500),
            CarbonImmutable::now(),
        );

        $deployment->forceFill(['member_states' => $snapshot->toArray()])->save();
    }

    /**
     * Fold a single container inspect (after a member action) into the
     * snapshot without pretending the whole stack was re-probed.
     *
     * @param  array<string, mixed>  $inspect  ContainerRuntimeInspector::inspect() result
     */
    public function applyInspect(ContainerDeployment $deployment, StackMember $member, array $inspect): void
    {
        $current = $this->snapshotFor($deployment);
        $containers = $current->containers;
        $now = CarbonImmutable::now();

        $containers[$member->containerName] = [
            'state' => ($inspect['missing'] ?? false)
                ? StackMemberState::missing($now)
                : StackMemberState::fromDocker((string) ($inspect['state'] ?? ''), null, $now),
            'service' => $member->composeKey,
        ];

        $snapshot = new StackMemberSnapshot(
            $containers,
            $current->checkedAt ?? $now,
            true,
            $current->error,
            $current->errorAt,
        );

        $deployment->forceFill([
            'member_states' => $snapshot->toArray(),
            'member_states_checked_at' => $current->checkedAt ?? $now,
        ])->save();
    }

    /**
     * @param  array<string, array{project: string, service: string, name: string, state: string, status: string, ports: string}>  $rows
     */
    private function write(ContainerDeployment $deployment, array $rows): StackMemberSnapshot
    {
        $now = CarbonImmutable::now();
        $containers = [];
        foreach ($rows as $name => $row) {
            $containers[$name] = [
                'state' => StackMemberState::fromDocker($row['state'], $row['status'], $now),
                'service' => $row['service'] !== '' ? $row['service'] : null,
            ];
        }

        $snapshot = new StackMemberSnapshot($containers, $now, true, null, null);
        $previous = $this->snapshotFor($deployment);

        if ($this->sameContainers($previous, $snapshot)
            && $previous->reachable
            && $previous->checkedAt !== null
            && $previous->checkedAt->gt($now->subMinutes(self::REWRITE_AFTER_MINUTES))) {
            return $previous;
        }

        $deployment->forceFill([
            'member_states' => $snapshot->toArray(),
            'member_states_checked_at' => $now,
        ])->save();

        return $snapshot;
    }

    private function sameContainers(StackMemberSnapshot $a, StackMemberSnapshot $b): bool
    {
        $strip = fn (StackMemberSnapshot $snapshot): array => collect($snapshot->containers)
            ->map(fn (array $row) => [$row['state']->state, $row['state']->status, $row['service']])
            ->sortKeys()
            ->all();

        return $strip($a) === $strip($b);
    }

    private function ssh(Node $node): SSHService
    {
        return $this->sshFactory ? ($this->sshFactory)($node) : SSHService::forNode($node);
    }

    private function staleAfterMinutes(): int
    {
        return max(1, (int) config('containers.member_states.stale_after_minutes', 15));
    }

    private function unknownAfterMinutes(): int
    {
        return max($this->staleAfterMinutes(), (int) config('containers.member_states.unknown_after_minutes', 60));
    }
}
