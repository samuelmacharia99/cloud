<?php

namespace App\Services\Customer;

use App\Models\CustomerProject;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

class CustomerProjectService
{
    /**
     * Create/link Project folders for multi-service or multi-container apps.
     */
    public function ensureForUser(User $user): void
    {
        $services = $user->services()
            ->with(['product', 'containerDeployment', 'project'])
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->whereHas('product', fn ($q) => $q->where('type', '!=', 'domain'))
            ->get()
            ->keyBy('id');

        if ($services->isEmpty()) {
            return;
        }

        $visited = [];

        foreach ($services as $service) {
            if (isset($visited[$service->id])) {
                continue;
            }

            $cluster = $this->relatedCluster($service, $services);
            foreach ($cluster as $member) {
                $visited[$member->id] = true;
            }

            $roles = $this->composeContainerLabels($cluster->first(
                fn (Service $s) => $s->isContainerHosting()
            ) ?? $cluster->first());

            if ($cluster->count() < 2 && count($roles) < 2) {
                continue;
            }

            $project = $this->resolveProjectForCluster($user, $cluster);
            foreach ($cluster as $member) {
                if ((int) $member->project_id !== (int) $project->id) {
                    $member->project_id = $project->id;
                    $member->save();
                }
            }
        }

        $this->pruneEmptyProjects($user);
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array{type: string, project?: CustomerProject, services: Collection<int, Service>, containers: list<string>}>
     */
    public function groupForDisplay(Collection $services): array
    {
        $groups = [];
        $used = [];

        $byProject = $services
            ->filter(fn (Service $s) => $s->project_id)
            ->groupBy('project_id');

        foreach ($byProject as $members) {
            /** @var Collection<int, Service> $members */
            $project = $members->first()?->project;
            if (! $project) {
                continue;
            }

            $primary = $members->first(fn (Service $s) => $s->isContainerHosting()) ?? $members->first();
            $containers = $this->composeContainerLabels($primary);

            if ($members->count() < 2 && count($containers) < 2) {
                continue;
            }

            $groups[] = [
                'type' => 'project',
                'project' => $project,
                'services' => $members->values(),
                'containers' => $containers,
            ];

            foreach ($members as $member) {
                $used[$member->id] = true;
            }
        }

        $ungrouped = $services
            ->reject(fn (Service $service) => isset($used[$service->id]))
            ->values();

        if ($ungrouped->isNotEmpty()) {
            $groups[] = [
                'type' => 'service',
                'services' => $ungrouped,
                'containers' => [],
            ];
        }

        return $groups;
    }

    /**
     * Ensure related services (bundled email / staging) share the anchor's project.
     */
    public function syncRelated(Service $anchor): void
    {
        $anchor->loadMissing(['product', 'containerDeployment', 'project', 'user']);

        if (! $anchor->user) {
            return;
        }

        $services = $anchor->user->services()
            ->with(['product', 'containerDeployment', 'project'])
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->get()
            ->keyBy('id');

        if (! $services->has($anchor->id)) {
            $services->put($anchor->id, $anchor);
        }

        $cluster = $this->relatedCluster($anchor, $services);
        $roles = $this->composeContainerLabels(
            $cluster->first(fn (Service $s) => $s->isContainerHosting()) ?? $anchor
        );

        if ($cluster->count() < 2 && count($roles) < 2) {
            return;
        }

        $project = $this->resolveProjectForCluster($anchor->user, $cluster);
        foreach ($cluster as $member) {
            if ((int) $member->project_id !== (int) $project->id) {
                $member->project_id = $project->id;
                $member->save();
            }
        }
    }

    /**
     * Human-readable compose roles for a container service (Backend, Frontend, …).
     *
     * @return list<string>
     */
    public function composeContainerLabels(?Service $service): array
    {
        if (! $service?->isContainerHosting()) {
            return [];
        }

        $yaml = (string) ($service->containerDeployment?->docker_compose_content ?? '');
        $labels = [];

        $map = [
            'backend' => 'Backend',
            'frontend' => 'Frontend',
            'edge' => 'Edge',
            'app' => 'App',
            'web' => 'Web',
            'db' => 'Database',
            'mysql' => 'Database',
            'mariadb' => 'Database',
            'postgres' => 'Database',
            'postgresql' => 'Database',
            'redis' => 'Redis',
        ];

        foreach ($map as $key => $label) {
            if (preg_match('/^  '.preg_quote($key, '/').':\s*$/m', $yaml)) {
                $labels[$label] = true;
            }
        }

        if ($labels === [] && $service->product?->containerTemplate) {
            $composeServices = $service->product->containerTemplate->compose_services ?? [];
            if (is_array($composeServices) && $composeServices !== []) {
                $labels['App'] = true;
                foreach (array_keys($composeServices) as $key) {
                    $label = $map[strtolower((string) $key)] ?? ucfirst((string) $key);
                    $labels[$label] = true;
                }
            }
        }

        return array_keys($labels);
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return Collection<int, Service>
     */
    private function relatedCluster(Service $anchor, Collection $services): Collection
    {
        $ids = [$anchor->id];
        $queue = [$anchor->id];

        while ($queue !== []) {
            $id = array_shift($queue);
            $service = $services->get($id);
            if (! $service) {
                continue;
            }

            foreach ($this->relatedServiceIds($service) as $relatedId) {
                if (! isset($services[$relatedId]) || in_array($relatedId, $ids, true)) {
                    continue;
                }
                $ids[] = $relatedId;
                $queue[] = $relatedId;
            }
        }

        return collect($ids)
            ->map(fn (int $id) => $services->get($id))
            ->filter()
            ->values();
    }

    /**
     * @return list<int>
     */
    private function relatedServiceIds(Service $service): array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $ids = [];

        foreach (['bundled_email_service_id', 'staging_service_id', 'production_service_id'] as $key) {
            $id = (int) ($meta[$key] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  Collection<int, Service>  $cluster
     */
    private function resolveProjectForCluster(User $user, Collection $cluster): CustomerProject
    {
        $existing = $cluster
            ->map(fn (Service $s) => $s->project)
            ->filter()
            ->first(fn (CustomerProject $p) => (int) $p->user_id === (int) $user->id);

        if ($existing) {
            return $existing;
        }

        return CustomerProject::create([
            'user_id' => $user->id,
            'name' => CustomerProject::DEFAULT_NAME,
        ]);
    }

    private function pruneEmptyProjects(User $user): void
    {
        CustomerProject::query()
            ->where('user_id', $user->id)
            ->whereDoesntHave('services')
            ->delete();
    }
}
