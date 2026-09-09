<?php

namespace App\Services\Customer;

use App\Jobs\ProvisionContainerServiceJob;
use App\Models\CustomerProject;
use App\Models\Service;
use App\Services\Billing\ProjectRecipeService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\ResellerEnforcementService;
use Illuminate\Support\Facades\DB;

class ProjectNodeWebSplitService
{
    public const RECIPE = 'nodejs_web';

    /**
     * @var list<string>
     */
    private const GIT_META_KEYS = [
        'source_repo_url',
        'source_repo_branch',
        'source_repo_token_encrypted',
        'source_repo_connected_at',
        'auto_deploy_enabled',
        'auto_deploy_secret_hash',
        'auto_deploy_secret_encrypted',
        'auto_deploy_force_rebuild',
    ];

    public function __construct(
        private ProjectRecipeService $recipes,
        private InvoiceProvisioningService $invoiceProvisioning,
        private ResellerEnforcementService $resellerEnforcement,
    ) {}

    /**
     * When a project-hosted Node API has a separate frontend root, keep the API
     * on this container and provision a sibling Web container on the same plan.
     *
     * @return array{created: bool, frontend: Service}|null
     */
    public function apply(Service $service): ?array
    {
        $service->refresh()->loadMissing(['project', 'product']);
        if (! $this->shouldSplit($service)) {
            return null;
        }

        $frontendRoot = trim((string) ($service->service_meta['node_frontend_root'] ?? ''));
        $project = $service->project;
        if (! $project instanceof CustomerProject) {
            return null;
        }

        return DB::transaction(function () use ($service, $project, $frontendRoot): array {
            $existing = $this->existingFrontend($project, $service);
            $created = false;
            $frontend = $existing ?? $this->createFrontendService($service, $project, $frontendRoot);
            $created = $existing === null;

            $this->promoteApiService($service, $project, $frontend);

            return ['created' => $created, 'frontend' => $frontend];
        });
    }

    public function provisionNewFrontendIfNeeded(?array $split): void
    {
        if ($split === null || ! ($split['created'] ?? false)) {
            return;
        }

        $frontend = $split['frontend'] ?? null;
        if (! $frontend instanceof Service) {
            return;
        }

        $this->resellerEnforcement->assertCanProvision($frontend);

        if (! $this->invoiceProvisioning->shouldAutoProvisionService($frontend)) {
            return;
        }

        $frontend->update(['status' => 'provisioning']);
        ProvisionContainerServiceJob::dispatchForService((int) $frontend->id, deferUntilResponse: true);
    }

    private function shouldSplit(Service $service): bool
    {
        if (! $service->project_id || ! $service->isContainerHosting()) {
            return false;
        }
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs') {
            return false;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (($meta['project_role'] ?? null) === 'frontend') {
            return false;
        }
        if (strtolower((string) ($meta['frontend'] ?? 'none')) === 'none') {
            return false;
        }

        $backend = trim((string) ($meta['node_backend_root'] ?? ''));
        $frontend = trim((string) ($meta['node_frontend_root'] ?? ''));

        return $frontend !== '' && $backend !== '' && $frontend !== $backend;
    }

    private function existingFrontend(CustomerProject $project, Service $api): ?Service
    {
        $linkedId = (int) ($api->service_meta['frontend_service_id'] ?? 0);
        if ($linkedId > 0) {
            $linked = Service::query()
                ->where('id', $linkedId)
                ->where('project_id', $project->id)
                ->whereNotIn('status', ['cancelled', 'terminated'])
                ->first();
            if ($linked) {
                return $linked;
            }
        }

        return $project->services()
            ->where('id', '!=', $api->id)
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->get()
            ->first(function (Service $candidate) use ($api): bool {
                $meta = is_array($candidate->service_meta) ? $candidate->service_meta : [];

                return ($meta['project_role'] ?? null) === 'frontend'
                    && (int) ($meta['backend_service_id'] ?? 0) === (int) $api->id;
            });
    }

    private function createFrontendService(Service $api, CustomerProject $project, string $frontendRoot): Service
    {
        $apiMeta = is_array($api->service_meta) ? $api->service_meta : [];
        $slug = $this->recipes->projectSlug($project->name);
        $name = $this->uniqueServiceName($project, $this->recipes->roleServiceName($slug, 'web'));
        $templateId = (int) ($apiMeta['container_template_id'] ?? $api->product?->container_template_id ?? 0);

        $meta = [
            'project_recipe' => self::RECIPE,
            'project_role' => 'frontend',
            'project_role_label' => 'Web',
            'project_billing_anchor' => false,
            'included_on_project_plan' => true,
            'backend_service_id' => $api->id,
            'language_slug' => 'nodejs',
            'backend' => 'nodejs',
            'framework' => 'other',
            'frontend' => 'none',
            'application_stack' => 'Node.js Application',
            'deployment_platform' => 'container',
            'provision_template_slug' => 'nodejs',
            'node_backend_root' => $frontendRoot,
            'node_project_root' => $frontendRoot,
            'node_version_source' => $apiMeta['node_version_source'] ?? 'manual',
            'stack_builder_version' => (int) ($apiMeta['stack_builder_version'] ?? config('stack_builder.version', 1)),
            'resource_share' => [
                'cpu' => 0.45,
                'memory' => 0.45,
            ],
        ];
        if ($templateId > 0) {
            $meta['container_template_id'] = $templateId;
        }
        if (! empty($apiMeta['selected_version'])) {
            $meta['selected_version'] = $apiMeta['selected_version'];
        }
        foreach (self::GIT_META_KEYS as $key) {
            if (array_key_exists($key, $apiMeta) && $apiMeta[$key] !== null && $apiMeta[$key] !== '') {
                $meta[$key] = $apiMeta[$key];
            }
        }
        $meta['auto_deploy_run_composer'] = false;
        $meta['auto_deploy_run_migrations'] = false;

        return Service::create([
            'user_id' => $api->user_id,
            'product_id' => $api->product_id,
            'project_id' => $project->id,
            'order_item_id' => null,
            'invoice_id' => null,
            'reseller_id' => $api->reseller_id,
            'name' => $name,
            'status' => 'pending',
            'billing_cycle' => $api->billing_cycle,
            'custom_price' => 0,
            'next_due_date' => $api->next_due_date,
            'provisioning_driver_key' => $api->provisioning_driver_key ?: 'container',
            'node_id' => $api->node_id,
            'service_meta' => $meta,
        ]);
    }

    private function promoteApiService(Service $api, CustomerProject $project, Service $frontend): void
    {
        $meta = is_array($api->service_meta) ? $api->service_meta : [];
        $slug = $this->recipes->projectSlug($project->name);
        $apiName = $this->recipes->roleServiceName($slug, 'api');

        $meta['project_recipe'] = self::RECIPE;
        $meta['project_role'] = 'backend';
        $meta['project_role_label'] = 'API';
        $meta['project_billing_anchor'] = true;
        $meta['frontend'] = 'none';
        $meta['frontend_service_id'] = $frontend->id;
        $meta['sibling_service_id'] = $frontend->id;
        $meta['resource_share'] = [
            'cpu' => 0.55,
            'memory' => 0.55,
        ];
        unset($meta['node_frontend_root'], $meta['node_workloads']);

        $frontendMeta = is_array($frontend->service_meta) ? $frontend->service_meta : [];
        $frontendMeta['backend_service_id'] = $api->id;
        $frontendMeta['sibling_service_id'] = $api->id;
        $frontend->update(['service_meta' => $frontendMeta]);

        $payload = [
            'service_meta' => $meta,
        ];
        if ($this->shouldRenamePackageService($api, $apiName)) {
            $payload['name'] = $apiName;
        }
        $api->update($payload);

        if (! $project->billing_service_id) {
            $project->update(['billing_service_id' => $api->id]);
        }
    }

    private function shouldRenamePackageService(Service $api, string $apiName): bool
    {
        $current = trim((string) $api->name);
        if ($current === '' || strcasecmp($current, $apiName) === 0) {
            return true;
        }
        $productName = trim((string) ($api->product?->name ?? ''));

        return $productName !== '' && strcasecmp($current, $productName) === 0;
    }

    private function uniqueServiceName(CustomerProject $project, string $name): string
    {
        $base = $name;
        $suffix = 2;
        while ($project->services()->where('name', $name)->whereNotIn('status', ['cancelled', 'terminated'])->exists()) {
            $name = mb_substr($base.'-'.$suffix, 0, 100);
            $suffix++;
        }

        return $name;
    }
}
