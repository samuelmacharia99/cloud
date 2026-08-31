<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CustomerProject extends Model
{
    use HasFactory;

    public const DEFAULT_NAME = 'Project';

    public const PLAN_POOL_RECIPE = 'plan_pool';

    protected $fillable = [
        'user_id',
        'name',
        'billing_service_id',
        'recipe_key',
        'resource_pool',
    ];

    protected function casts(): array
    {
        return [
            'resource_pool' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'project_id');
    }

    public function billingService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'billing_service_id');
    }

    /**
     * True when every live service in the project is Application Hosting
     * (or the project only has already-ended container sites).
     */
    public function isApplicationHostingProject(): bool
    {
        $this->loadMissing('services.product');

        $live = $this->services->filter(function (Service $service): bool {
            $status = $service->status->value ?? (string) $service->status;

            return ! in_array($status, ['terminated', 'cancelled'], true);
        });

        if ($live->isNotEmpty()) {
            return $live->every(fn (Service $service) => $service->isContainerHosting());
        }

        return $this->services->contains(fn (Service $service) => $service->isContainerHosting());
    }

    /**
     * @return Collection<int, Service>
     */
    public function liveServices(): Collection
    {
        $this->loadMissing('services.product');

        return $this->services
            ->filter(function (Service $service): bool {
                $status = $service->status->value ?? (string) $service->status;

                return ! in_array($status, ['terminated', 'cancelled'], true);
            })
            ->values();
    }

    /**
     * @return Collection<int, Service>
     */
    public function liveApplicationHostingServices(): Collection
    {
        return $this->liveServices()
            ->filter(fn (Service $service) => $service->isContainerHosting())
            ->values();
    }

    public function resolvedBillingService(): ?Service
    {
        $this->loadMissing(['billingService.product.containerTemplate', 'billingService.containerDeployment', 'services.product']);

        if ($this->billingService && $this->billingService->isContainerHosting()) {
            $status = $this->billingService->status->value ?? (string) $this->billingService->status;
            if (! in_array($status, ['terminated', 'cancelled'], true)) {
                return $this->billingService;
            }
        }

        return $this->liveApplicationHostingServices()->first();
    }

    public function canDeployIncludedWorkload(): bool
    {
        $anchor = $this->resolvedBillingService();
        if (! $anchor) {
            return false;
        }

        $status = $anchor->status->value ?? (string) $anchor->status;

        return in_array($status, ['active', 'provisioning'], true);
    }

    /**
     * @return array{cpu: float, memory_mb: int, disk_gb: float}|null
     */
    public function includedPlanLimits(): ?array
    {
        $anchor = $this->resolvedBillingService();
        $product = $anchor?->product;
        if (! $product) {
            return null;
        }

        return $product->getIncludedContainerLimits(
            $product->containerTemplate,
            $anchor->containerDeployment
        );
    }

    public function memberCount(): int
    {
        return 1;
    }

    public function resourceCount(): int
    {
        return $this->liveServices()->count();
    }
}
