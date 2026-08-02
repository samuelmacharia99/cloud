<?php

namespace App\Services\Customer;

use App\Models\CustomerProject;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomerProjectService
{
    /**
     * Auto-link projects for multi-service / multi-container apps (incl. Laravel+Next).
     */
    public function ensureForUser(User $user): void
    {
        $services = $user->services()
            ->with(['product.containerTemplate', 'containerDeployment', 'project'])
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

            if (! $this->clusterNeedsProject($cluster)) {
                continue;
            }

            // Don't steal services the customer already placed in another project.
            $alreadyGrouped = $cluster->filter(fn (Service $s) => $s->project_id);
            if ($alreadyGrouped->isNotEmpty()) {
                $project = $alreadyGrouped
                    ->map(fn (Service $s) => $s->project)
                    ->filter()
                    ->first(fn (CustomerProject $p) => (int) $p->user_id === (int) $user->id);

                if ($project) {
                    foreach ($cluster as $member) {
                        if (! $member->project_id) {
                            $member->project_id = $project->id;
                            $member->save();
                        }
                    }

                    continue;
                }
            }

            $project = $this->resolveProjectForCluster($user, $cluster);
            foreach ($cluster as $member) {
                if ((int) $member->project_id !== (int) $project->id) {
                    $member->project_id = $project->id;
                    $member->save();
                }
            }
        }
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
            ->groupBy('project_id')
            ->sortBy(fn (Collection $members) => mb_strtolower((string) ($members->first()?->project?->name ?? '')));

        foreach ($byProject as $members) {
            /** @var Collection<int, Service> $members */
            $project = $members->first()?->project;
            if (! $project) {
                continue;
            }

            $primary = $members->first(fn (Service $s) => $s->isContainerHosting()) ?? $members->first();

            $groups[] = [
                'type' => 'project',
                'project' => $project,
                'services' => $members->values(),
                'containers' => $this->composeContainerLabels($primary),
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

    public function syncRelated(Service $anchor): void
    {
        $anchor->loadMissing(['product.containerTemplate', 'containerDeployment', 'project', 'user']);

        if (! $anchor->user) {
            return;
        }

        $services = $anchor->user->services()
            ->with(['product.containerTemplate', 'containerDeployment', 'project'])
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->get()
            ->keyBy('id');

        if (! $services->has($anchor->id)) {
            $services->put($anchor->id, $anchor);
        }

        $cluster = $this->relatedCluster($anchor, $services);

        if (! $this->clusterNeedsProject($cluster)) {
            return;
        }

        $project = $this->resolveProjectForCluster($anchor->user, $cluster);
        foreach ($cluster as $member) {
            if ($member->project_id && (int) $member->project_id !== (int) $project->id) {
                continue;
            }
            if ((int) $member->project_id !== (int) $project->id) {
                $member->project_id = $project->id;
                $member->save();
            }
        }
    }

    public function createProject(User $user, string $name, ?Service $firstService = null): CustomerProject
    {
        $project = CustomerProject::create([
            'user_id' => $user->id,
            'name' => $name,
        ]);

        if ($firstService) {
            $this->assignService($firstService, $project);
        }

        return $project;
    }

    public function assignService(Service $service, ?CustomerProject $project): void
    {
        if ($project && (int) $project->user_id !== (int) $service->user_id) {
            throw ValidationException::withMessages([
                'project_id' => 'That project does not belong to this account.',
            ]);
        }

        $service->project_id = $project?->id;
        $service->save();

        if ($project === null) {
            $this->pruneEmptyProjects($service->user);
        }
    }

    /**
     * Human-readable compose / intended roles (Backend, Frontend, …).
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

        // Laravel + Next is one billed service; sidecars may not exist in compose yet.
        if ($this->intendsLaravelNextStack($service)) {
            $labels['Backend'] = true;
            $labels['Frontend'] = true;
            $labels['Edge'] = true;
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $db = strtolower((string) ($meta['database'] ?? $meta['database_id'] ?? ''));
            if ($db !== '' && $db !== 'none' && $db !== 'sqlite') {
                $labels['Database'] = true;
            } elseif (isset($labels['Database']) === false && (
                str_contains($yaml, "\n  db:\n")
                || str_contains($yaml, "\n  mysql:\n")
                || str_contains($yaml, "\n  postgres:\n")
            )) {
                $labels['Database'] = true;
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

    public function intendsLaravelNextStack(Service $service): bool
    {
        if (! $service->isContainerHosting()) {
            return false;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $frontend = strtolower((string) ($meta['frontend'] ?? ''));

        if (in_array($frontend, ['nextjs', 'next', 'next.js'], true)) {
            return true;
        }

        // Explicit stack flags written during provisioning.
        if (! empty($meta['laravel_next_sidecar']) || ! empty($meta['uses_next_sidecar'])) {
            return true;
        }

        $yaml = (string) ($service->containerDeployment?->docker_compose_content ?? '');

        return str_contains($yaml, "\n  frontend:\n")
            && str_contains($yaml, "\n  edge:\n")
            && str_contains($yaml, "\n  backend:\n");
    }

    /**
     * @param  Collection<int, Service>  $cluster
     */
    private function clusterNeedsProject(Collection $cluster): bool
    {
        if ($cluster->count() >= 2) {
            return true;
        }

        $primary = $cluster->first(fn (Service $s) => $s->isContainerHosting()) ?? $cluster->first();

        return count($this->composeContainerLabels($primary)) >= 2;
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

        $name = CustomerProject::DEFAULT_NAME;
        $primary = $cluster->first(fn (Service $s) => $s->isContainerHosting()) ?? $cluster->first();
        if ($primary?->name) {
            $name = $primary->name;
        }

        return CustomerProject::create([
            'user_id' => $user->id,
            'name' => mb_substr($name, 0, 100),
        ]);
    }

    private function pruneEmptyProjects(?User $user): void
    {
        if (! $user) {
            return;
        }

        CustomerProject::query()
            ->where('user_id', $user->id)
            ->whereDoesntHave('services')
            ->delete();
    }
}
