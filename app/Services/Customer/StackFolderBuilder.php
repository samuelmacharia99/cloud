<?php

namespace App\Services\Customer;

use App\Models\Service;
use App\Services\Provisioning\StackMember;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\Provisioning\StackMemberStateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Groups a project's container services into stack folders.
 *
 * A folder is a billing anchor plus the role services that were split off
 * it and linked to it (backend_service_id / frontend_service_id /
 * sibling_service_id, in either direction). Staging, production and
 * bundled-email links are deliberately not folder links, and neither is a
 * shared project_recipe: those are separate stacks with their own compose
 * files. A standalone service is a folder of one.
 */
final class StackFolderBuilder
{
    public const ROLE_LINK_KEYS = ['backend_service_id', 'frontend_service_id', 'sibling_service_id'];

    public function __construct(
        private StackMemberResolver $resolver,
        private StackMemberStateService $states,
    ) {}

    /**
     * @param  Collection<int, Service>  $services  container-hosting services of one project
     * @return list<StackFolder>
     */
    public function foldersFor(Collection $services): array
    {
        $pool = $services->filter(fn (Service $service) => $service->isContainerHosting())->keyBy('id');
        $folders = [];
        $used = [];

        foreach ($this->orderedAnchors($pool) as $candidate) {
            if (isset($used[$candidate->id])) {
                continue;
            }

            $folder = $this->folderFor($candidate, $pool);
            foreach ($folder->services as $member) {
                $used[$member->id] = true;
            }
            $folders[] = $folder;
        }

        return $folders;
    }

    /**
     * @param  Collection<int, Service>  $pool  keyed by id
     */
    public function folderFor(Service $anchor, Collection $pool): StackFolder
    {
        $cluster = $this->cluster($anchor, $pool);
        $anchor = $this->pickAnchor($cluster);
        $services = $cluster->sortBy(fn (Service $service) => $service->id === $anchor->id ? 0 : 1)->values();

        $members = [];
        $checkedAt = null;
        $stale = false;
        $lastError = null;
        foreach ($services as $service) {
            $serviceMembers = $this->resolver->membersForService($service);
            $deployment = $service->containerDeployment;
            if ($deployment) {
                $serviceMembers = $this->states->overlay($deployment, $serviceMembers);
                $snapshot = $this->states->snapshotFor($deployment);
                if ($snapshot->checkedAt && ($checkedAt === null || $snapshot->checkedAt->lt($checkedAt))) {
                    $checkedAt = $snapshot->checkedAt;
                }
                $stale = $stale || $this->states->isStale($deployment);
                $lastError ??= $snapshot->reachable ? null : $snapshot->error;
            }
            array_push($members, ...$serviceMembers);
        }

        return new StackFolder(
            key: 'stack-'.$anchor->id,
            name: $anchor->customerServiceName(),
            anchor: $anchor,
            services: $services,
            members: $members,
            aggregateState: StackFolder::aggregateOf($members),
            checkedAt: $checkedAt,
            stale: $stale,
            lastError: $lastError,
        );
    }

    /**
     * Cheap rollup for the index card, from snapshots only.
     *
     * @param  Collection<int, Service>  $services
     * @return array{members: int, running: int, not_running: int, state: string, checked_at: ?CarbonImmutable, stale: bool}
     */
    public function summaryFor(Collection $services): array
    {
        $folders = $this->foldersFor($services);
        $members = array_merge([], ...array_map(fn (StackFolder $folder) => $folder->members, $folders));

        $checkedAt = null;
        foreach ($folders as $folder) {
            if ($folder->checkedAt && ($checkedAt === null || $folder->checkedAt->lt($checkedAt))) {
                $checkedAt = $folder->checkedAt;
            }
        }

        return [
            'members' => count($members),
            'running' => count(array_filter($members, fn (StackMember $member) => $member->state->isRunning())),
            'not_running' => count(array_filter($members, fn (StackMember $member) => $member->state->isKnown() && ! $member->state->isRunning())),
            'state' => $members === [] ? StackFolder::PENDING : StackFolder::aggregateOf($members),
            'checked_at' => $checkedAt,
            'stale' => collect($folders)->contains(fn (StackFolder $folder) => $folder->stale),
        ];
    }

    /**
     * Anchors first so a folder is named after its billing service, then by
     * name so the page is stable between reloads.
     *
     * @param  Collection<int, Service>  $pool
     * @return Collection<int, Service>
     */
    private function orderedAnchors(Collection $pool): Collection
    {
        return $pool->sortBy(fn (Service $service) => [
            ! empty(($service->service_meta ?: [])['project_billing_anchor']) ? 0 : 1,
            mb_strtolower($service->customerServiceName()),
            $service->id,
        ])->values();
    }

    /**
     * @param  Collection<int, Service>  $pool
     * @return Collection<int, Service>
     */
    private function cluster(Service $start, Collection $pool): Collection
    {
        $ids = [$start->id => true];
        $queue = [$start->id];

        while ($queue !== []) {
            $current = $pool->get(array_shift($queue));
            if (! $current) {
                continue;
            }

            foreach ($this->linkedIds($current, $pool) as $linked) {
                if (isset($ids[$linked])) {
                    continue;
                }
                $ids[$linked] = true;
                $queue[] = $linked;
            }
        }

        return collect(array_keys($ids))->map(fn (int $id) => $pool->get($id))->filter()->values();
    }

    /**
     * @param  Collection<int, Service>  $pool
     * @return list<int>
     */
    private function linkedIds(Service $service, Collection $pool): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $ids = [];

        foreach (self::ROLE_LINK_KEYS as $key) {
            $id = (int) ($meta[$key] ?? 0);
            if ($id > 0 && $pool->has($id)) {
                $ids[] = $id;
            }
        }

        // Reverse links: a sibling that points at this service.
        foreach ($pool as $other) {
            $otherMeta = is_array($other->service_meta) ? $other->service_meta : [];
            foreach (self::ROLE_LINK_KEYS as $key) {
                if ((int) ($otherMeta[$key] ?? 0) === (int) $service->id) {
                    $ids[] = (int) $other->id;
                }
            }
        }

        // Deliberately no "same recipe" rule: a DirectAdmin convert with extra
        // sites shares one recipe across separate stacks, and those must stay
        // separate folders. Real splits (Laravel + Next, Node API + Web) carry
        // explicit links both ways.
        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<int, Service>  $cluster
     */
    private function pickAnchor(Collection $cluster): Service
    {
        $flagged = $cluster->first(fn (Service $service) => ! empty(($service->service_meta ?: [])['project_billing_anchor']));
        if ($flagged) {
            return $flagged;
        }

        $linking = $cluster->first(function (Service $service) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];

            return (int) ($meta['backend_service_id'] ?? 0) === (int) $service->id
                || (int) ($meta['frontend_service_id'] ?? 0) > 0;
        });
        if ($linking) {
            return $linking;
        }

        return $cluster->sortBy('id')->first();
    }
}
