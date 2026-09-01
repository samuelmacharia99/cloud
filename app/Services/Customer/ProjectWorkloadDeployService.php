<?php

namespace App\Services\Customer;

use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\DatabaseTemplate;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ProjectRecipeService;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ResellerEnforcementService;
use App\Services\TechStackRoutingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectWorkloadDeployService
{
    public function __construct(
        private CustomerProjectService $projects,
        private ProjectRecipeService $recipes,
        private ProvisioningService $provisioning,
        private InvoiceProvisioningService $invoiceProvisioning,
        private ResellerEnforcementService $resellerEnforcement,
    ) {}

    /**
     * Deploy another Application Hosting site on the project's existing plan.
     * No invoice is created — plan billing continues, overage is metered.
     */
    public function deploy(
        User $user,
        CustomerProject $project,
        ContainerTemplate $language,
        ?DatabaseTemplate $database,
        ?string $framework,
        ?string $frontend,
        ?string $selectedVersion = null,
    ): Service {
        if ((int) $project->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'project' => 'That project does not belong to this account.',
            ]);
        }

        $project->loadMissing([
            'billingService.product.containerTemplate',
            'billingService.containerDeployment',
            'services.product',
        ]);

        $this->projects->ensurePlanPool($project);
        $project->refresh()->loadMissing([
            'billingService.product.containerTemplate',
            'services.product',
        ]);

        $anchor = $project->resolvedBillingService();
        if (! $anchor || ! $anchor->isContainerHosting()) {
            throw ValidationException::withMessages([
                'project' => 'Buy an Application Hosting plan for this project before deploying more services.',
            ]);
        }

        $status = $anchor->status->value ?? (string) $anchor->status;
        if ($status === 'pending') {
            throw ValidationException::withMessages([
                'project' => 'Pay the project plan before deploying more services.',
            ]);
        }
        if (! in_array($status, ['active', 'provisioning'], true)) {
            throw ValidationException::withMessages([
                'project' => 'The project plan must be active before you can deploy another service.',
            ]);
        }

        if ($database && $database->hosting_type !== 'container') {
            throw ValidationException::withMessages([
                'database_id' => 'Selected database is not available for application hosting.',
            ]);
        }

        if (! TechStackRoutingService::isValidStackSelection($language, $framework, $frontend, $database)) {
            throw ValidationException::withMessages([
                'language_id' => 'Invalid tech stack combination selected.',
            ]);
        }

        $requiredVersions = TechStackRoutingService::requiredSelectedVersions($language);
        if ($requiredVersions !== []) {
            if (! in_array((string) $selectedVersion, $requiredVersions, true)) {
                throw ValidationException::withMessages([
                    'selected_version' => 'Choose a '.strtolower(TechStackRoutingService::versionPickerPayload($language)['label']).'.',
                ]);
            }
        }

        $roles = TechStackRoutingService::resolveDefaultRoles($language, $framework, $frontend);
        $product = $anchor->product;
        $recipe = $project->recipe_key ?: CustomerProject::PLAN_POOL_RECIPE;
        $resourceShare = $project->fresh()->resolveIncludedWorkloadShare();
        $serviceName = $this->recipes->roleServiceName(
            $this->recipes->projectSlug($project->name),
            Str::slug($language->slug ?: $language->name) ?: 'app'
        );

        $meta = [
            'project_recipe' => $recipe,
            'project_role' => 'workload',
            'project_role_label' => $language->name,
            'project_billing_anchor' => false,
            'container_template_id' => $language->id,
            'application_stack' => $language->name,
            'language_slug' => $language->slug,
            'backend' => $roles['backend'],
            'framework' => $roles['framework'],
            'frontend' => $roles['frontend'],
            'provision_template_slug' => $language->slug,
            'deployment_platform' => 'container',
            'stack_builder_version' => (int) config('stack_builder.version', 1),
            'included_on_project_plan' => true,
            'resource_share' => [
                'cpu' => $resourceShare['cpu'],
                'memory' => $resourceShare['memory'],
            ],
        ];

        if ($selectedVersion !== null && $selectedVersion !== '') {
            $meta['selected_version'] = $selectedVersion;
        }

        if ($database) {
            $meta['database_id'] = $database->id;
            $meta['database_template_name'] = $database->name;
        }

        $service = Service::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'order_item_id' => null,
            'invoice_id' => null,
            'reseller_id' => $anchor->reseller_id ?? $user->reseller_id,
            'name' => $serviceName,
            'status' => 'pending',
            'billing_cycle' => $anchor->billing_cycle,
            'custom_price' => 0,
            'next_due_date' => $anchor->next_due_date,
            'provisioning_driver_key' => $product->provisioning_driver_key ?: 'container',
            'node_id' => $anchor->node_id,
            'service_meta' => $meta,
        ]);

        $this->resellerEnforcement->assertCanProvision($service);

        if (! $this->invoiceProvisioning->shouldAutoProvisionService($service)) {
            return $service->fresh();
        }

        try {
            $service->update(['status' => 'provisioning']);
            $this->provisioning->provision($service->fresh());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'language_id' => 'The service was created but provisioning failed: '.$e->getMessage(),
            ]);
        }

        return $service->fresh();
    }
}
