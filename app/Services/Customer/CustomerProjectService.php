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
     * @param  Collection<int, CustomerProject>|null  $allProjects
     * @return list<array{type: string, project?: CustomerProject, services: Collection<int, Service>, containers: list<string>}>
     */
    public function groupForDisplay(Collection $services, ?Collection $allProjects = null): array
    {
        $groups = [];
        $used = [];
        $seenProjectIds = [];

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
            $containers = $this->composeContainerLabels($primary);

            // Prefer explicit role labels from split project recipe services.
            $roleLabels = $members
                ->map(function (Service $s) {
                    $meta = is_array($s->service_meta) ? $s->service_meta : [];

                    return $meta['project_role_label'] ?? null;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($roleLabels !== []) {
                $containers = $roleLabels;
            }

            $groups[] = [
                'type' => 'project',
                'project' => $project,
                'services' => $members->values(),
                'containers' => $containers,
            ];
            $seenProjectIds[$project->id] = true;

            foreach ($members as $member) {
                $used[$member->id] = true;
            }
        }

        if ($allProjects) {
            $empty = $allProjects
                ->reject(fn (CustomerProject $project) => isset($seenProjectIds[$project->id]))
                ->sortBy(fn (CustomerProject $project) => mb_strtolower($project->name));

            foreach ($empty as $project) {
                $groups[] = [
                    'type' => 'project',
                    'project' => $project,
                    'services' => collect(),
                    'containers' => [],
                ];
            }

            $groups = collect($groups)
                ->sortBy(fn (array $group) => mb_strtolower((string) ($group['project']->name ?? 'zzz')))
                ->values()
                ->all();
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

    public function attachPaidServiceFromSession(User $user, Service $service): void
    {
        if (! $service->isContainerHosting()) {
            return;
        }

        $session = session('selected_techstack', []);
        $projectId = (int) ($session['project_id'] ?? 0);
        if ($projectId <= 0) {
            return;
        }

        $project = CustomerProject::query()
            ->where('user_id', $user->id)
            ->whereKey($projectId)
            ->first();

        if (! $project) {
            return;
        }

        if (! $service->project_id) {
            $this->assignService($service, $project);
        }

        $this->ensurePlanPool($project->fresh(['services.product', 'billingService']));
    }

    /**
     * Existing empty project from “choose a plan to start”, if the customer owns it.
     */
    public function projectFromTechstackSession(User $user): ?CustomerProject
    {
        $session = session('selected_techstack', []);
        $projectId = (int) ($session['project_id'] ?? 0);
        if ($projectId <= 0) {
            return null;
        }

        return CustomerProject::query()
            ->where('user_id', $user->id)
            ->whereKey($projectId)
            ->first();
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

        if ($project) {
            $this->ensurePlanPool($project->fresh(['services.product', 'billingService']));
        }

        if ($project === null) {
            $this->pruneEmptyProjects($service->user);
        }
    }

    /**
     * Point the project at a billed Application Hosting plan and mark extras as included.
     */
    public function ensurePlanPool(CustomerProject $project): void
    {
        $project->loadMissing(['services.product', 'billingService']);

        $anchor = $project->resolvedBillingService();
        if (! $anchor) {
            return;
        }

        $updates = [];
        if (! $project->billing_service_id) {
            $updates['billing_service_id'] = $anchor->id;
        }
        if (! $project->recipe_key) {
            $updates['recipe_key'] = CustomerProject::PLAN_POOL_RECIPE;
        }
        if ($updates !== []) {
            $project->update($updates);
        }

        $this->markAnchorMeta($anchor, $project);

        foreach ($project->liveApplicationHostingServices() as $member) {
            if ((int) $member->id === (int) $anchor->id) {
                continue;
            }
            $this->markIncludedWorkloadMeta($member, $project);
        }
    }

    public function markAnchorMeta(Service $service, CustomerProject $project): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (! empty($meta['project_recipe']) && ! empty($meta['project_billing_anchor'])) {
            return;
        }

        $recipe = $project->recipe_key ?: CustomerProject::PLAN_POOL_RECIPE;
        if (empty($meta['project_recipe'])) {
            $meta['project_recipe'] = $recipe;
        }
        if (empty($meta['project_role'])) {
            $meta['project_role'] = 'primary';
            $meta['project_role_label'] = 'Primary';
        }
        $meta['project_billing_anchor'] = true;
        $service->update(['service_meta' => $meta]);
    }

    public function markIncludedWorkloadMeta(Service $service, CustomerProject $project): void
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (! empty($meta['project_recipe']) && array_key_exists('project_billing_anchor', $meta) && ! $meta['project_billing_anchor']) {
            return;
        }

        $recipe = $project->recipe_key ?: CustomerProject::PLAN_POOL_RECIPE;
        $meta['project_recipe'] = $meta['project_recipe'] ?? $recipe;
        $meta['project_role'] = $meta['project_role'] ?? 'workload';
        $meta['project_role_label'] = $meta['project_role_label'] ?? 'Service';
        $meta['project_billing_anchor'] = false;
        $service->update(['service_meta' => $meta]);
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

        // Laravel + Next as separate project role services.
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (($meta['project_role'] ?? null) === 'backend') {
            $labels['API'] = true;
        } elseif (($meta['project_role'] ?? null) === 'frontend') {
            $labels['Web'] = true;
        }

        // Legacy Laravel + Next is one billed service; sidecars may not exist in compose yet.
        if ($this->intendsLaravelNextStack($service) && empty($meta['project_recipe'])) {
            $labels['Backend'] = true;
            $labels['Frontend'] = true;
            $labels['Edge'] = true;
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

        // Split project recipe (API + Web services) is not the legacy Compose sidecar stack.
        if (! empty($meta['project_recipe'])) {
            return false;
        }

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

        foreach ([
            'bundled_email_service_id',
            'staging_service_id',
            'production_service_id',
            'sibling_service_id',
            'backend_service_id',
            'frontend_service_id',
        ] as $key) {
            $id = (int) ($meta[$key] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Suspend or restore all non-anchor project role siblings when the billing anchor changes status.
     *
     * @return list<Service>
     */
    public function siblingRoleServices(Service $anchor): array
    {
        $meta = is_array($anchor->service_meta) ? $anchor->service_meta : [];
        if (empty($meta['project_billing_anchor']) || empty($meta['project_recipe'])) {
            return [];
        }

        $ids = [];
        foreach (['frontend_service_id', 'sibling_service_id'] as $key) {
            $id = (int) ($meta[$key] ?? 0);
            if ($id > 0 && $id !== (int) $anchor->id) {
                $ids[] = $id;
            }
        }

        if ($anchor->project_id) {
            $projectSiblings = Service::query()
                ->where('project_id', $anchor->project_id)
                ->where('id', '!=', $anchor->id)
                ->where('product_id', $anchor->product_id)
                ->get();

            foreach ($projectSiblings as $sibling) {
                $siblingMeta = is_array($sibling->service_meta) ? $sibling->service_meta : [];
                if (($siblingMeta['project_recipe'] ?? null) === ($meta['project_recipe'] ?? null)
                    && empty($siblingMeta['project_billing_anchor'])) {
                    $ids[] = (int) $sibling->id;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return Service::query()->whereIn('id', $ids)->get()->all();
    }

    public function syncStatusToProjectRoles(Service $anchor, string $status): void
    {
        foreach ($this->siblingRoleServices($anchor) as $sibling) {
            if ($sibling->status === $status) {
                continue;
            }
            $sibling->status = $status;
            $sibling->save();
        }
    }

    /**
     * @return list<int>
     */
    public function pendingProjectRoleServiceIds(Service $anchor): array
    {
        return collect($this->siblingRoleServices($anchor))
            ->filter(fn (Service $s) => in_array($s->status, ['pending', 'provisioning'], true))
            ->map(fn (Service $s) => (int) $s->id)
            ->values()
            ->all();
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
