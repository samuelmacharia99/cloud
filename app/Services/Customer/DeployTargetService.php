<?php

namespace App\Services\Customer;

use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerCustomerCatalogService;
use Illuminate\Support\Collection;

/**
 * What the deploy page offers before a stack is chosen: the customer's
 * existing plans that still have room, and the plans they can buy.
 */
class DeployTargetService
{
    public function __construct(
        private ResellerCustomerCatalogService $catalog,
        private StackEligibilityService $eligibility,
    ) {}

    /**
     * Every project of the customer with an Application Hosting plan, full
     * ones included (they render disabled with the reason and an upgrade link).
     *
     * @return Collection<int, DeployTarget>
     */
    public function existingPlans(User $user): Collection
    {
        $templates = null;

        return $user->customerProjects()
            ->with(['billingService.product.containerTemplate', 'billingService.containerDeployment', 'services.product.containerTemplate', 'services.containerDeployment'])
            ->orderBy('name')
            ->get()
            ->map(function (CustomerProject $project) use (&$templates): ?DeployTarget {
                $anchor = $project->resolvedBillingService();
                if (! $anchor || ! $anchor->isContainerHosting()) {
                    return null;
                }

                $usage = $project->planUsageSummary();
                $pinned = $anchor->product?->container_template_id ? (int) $anchor->product->container_template_id : null;
                $limits = $project->includedPlanLimits() ?? ['cpu' => null, 'memory_mb' => null];
                $choices = $this->eligibility->forPlan($pinned, $limits, $templates ??= ContainerTemplate::offeredForNewDeploy()->catalogOrder()->get());

                return new DeployTarget(
                    project: $project,
                    anchor: $anchor,
                    planName: $anchor->customerPlanName(),
                    serviceCount: $project->liveApplicationHostingServices()->count(),
                    remainingCpuShare: (float) ($usage['remaining_cpu_share'] ?? 0.0),
                    remainingMemoryShare: (float) ($usage['remaining_memory_share'] ?? 0.0),
                    hasRoom: $project->hasRoomForIncludedWorkload(),
                    fullReason: $project->includedWorkloadRoomReason(),
                    pinnedTemplateId: $pinned,
                    limits: $limits,
                    eligibleStackCount: $choices->filter(fn (StackChoice $choice) => $choice->eligible)->count(),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * Plans the customer can buy: platform application hosting for platform
     * customers, the reseller's container listings for reseller customers.
     *
     * @return Collection<int, PlanOffer>
     */
    public function newPlans(User $user): Collection
    {
        $templates = ContainerTemplate::offeredForNewDeploy()->catalogOrder()->get();

        if ($this->catalog->isResellerCustomer($user)) {
            return $this->catalog->activeCatalog($user)
                ->filter(fn (ResellerProduct $listing) => $listing->type === 'container_hosting' && $listing->isOrderable())
                ->map(function (ResellerProduct $listing) use ($templates): ?PlanOffer {
                    $product = $listing->provisionProduct();
                    if (! $product) {
                        return null;
                    }
                    $pinned = $listing->container_template_id ?? $product->container_template_id;
                    $limits = is_array($listing->resource_limits) && $listing->resource_limits !== []
                        ? $listing->resource_limits
                        : (is_array($product->resource_limits) ? $product->resource_limits : []);

                    return $this->offer(
                        productId: (int) $product->id,
                        resellerProductId: (int) $listing->id,
                        name: (string) $listing->name,
                        description: $listing->description ?? $product->description,
                        monthly: (float) ($listing->monthly_price ?? 0),
                        yearly: $listing->yearly_price !== null ? (float) $listing->yearly_price : null,
                        features: $listing->features ?? $product->features ?? [],
                        limits: $limits,
                        pinnedTemplateId: $pinned ? (int) $pinned : null,
                        featured: (bool) $product->featured,
                        templates: $templates,
                    );
                })
                ->filter()
                ->sortBy(fn (PlanOffer $offer) => $offer->monthlyPrice)
                ->values();
        }

        $query = Product::query()
            ->with('containerTemplate')
            ->where('is_active', true)
            ->where('type', 'container_hosting')
            ->orderByRaw('COALESCE(monthly_price, yearly_price / 12, 0) ASC')
            ->orderBy('name');

        return $this->catalog->scopePlatformProducts($query, $user)
            ->get()
            ->map(fn (Product $product) => $this->offer(
                productId: (int) $product->id,
                resellerProductId: null,
                name: (string) $product->name,
                description: $product->description,
                monthly: (float) $product->monthly_price,
                yearly: $product->yearly_price !== null ? (float) $product->yearly_price : null,
                features: is_array($product->features) ? $product->features : [],
                limits: is_array($product->resource_limits) ? $product->resource_limits : [],
                pinnedTemplateId: $product->container_template_id ? (int) $product->container_template_id : null,
                featured: (bool) $product->featured,
                templates: $templates,
            ))
            ->values();
    }

    /**
     * A single offer by the identifiers the deploy page posts back, verified
     * against the same catalogue rules; null when the customer may not buy it.
     */
    public function findOffer(User $user, ?int $productId, ?int $resellerProductId): ?PlanOffer
    {
        return $this->newPlans($user)->first(function (PlanOffer $offer) use ($productId, $resellerProductId) {
            if ($resellerProductId !== null) {
                return $offer->resellerProductId === $resellerProductId;
            }

            return $offer->resellerProductId === null && $offer->productId === $productId;
        });
    }

    /**
     * @param  list<string>  $features
     * @param  array<string, mixed>  $limits
     * @param  Collection<int, ContainerTemplate>  $templates
     */
    private function offer(
        int $productId,
        ?int $resellerProductId,
        string $name,
        ?string $description,
        float $monthly,
        ?float $yearly,
        array $features,
        array $limits,
        ?int $pinnedTemplateId,
        bool $featured,
        Collection $templates,
    ): PlanOffer {
        $choices = $this->eligibility->forPlan($pinnedTemplateId, StackEligibilityService::limitsFromResourceLimits($limits), $templates);
        $pinnedName = $pinnedTemplateId ? $templates->firstWhere('id', $pinnedTemplateId)?->name : null;

        return new PlanOffer(
            productId: $productId,
            resellerProductId: $resellerProductId,
            name: $name,
            description: $description,
            monthlyPrice: $monthly,
            yearlyPrice: $yearly,
            features: array_values(array_filter(array_map('strval', $features))),
            resourceLimits: $limits,
            pinnedTemplateId: $pinnedTemplateId,
            pinnedTemplateName: $pinnedName,
            eligibleStackCount: $choices->filter(fn (StackChoice $choice) => $choice->eligible)->count(),
            featured: $featured,
        );
    }
}
