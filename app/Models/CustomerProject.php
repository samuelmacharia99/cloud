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
        'consumption_snapshot',
        'consumption_snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'resource_pool' => 'array',
            'consumption_snapshot' => 'array',
            'consumption_snapshot_at' => 'datetime',
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

    /**
     * @return array{cpu: float, memory: float}
     */
    public function allocatedResourceShares(): array
    {
        $cpu = 0.0;
        $memory = 0.0;
        $anchorId = $this->resolvedBillingService()?->id;

        foreach ($this->liveApplicationHostingServices() as $service) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $isAnchor = $anchorId && (int) $service->id === (int) $anchorId;
            $share = $meta['resource_share'] ?? null;

            if (is_array($share)) {
                $cpu += (float) ($share['cpu'] ?? 0);
                $memory += (float) ($share['memory'] ?? 0);

                continue;
            }

            if (! $isAnchor) {
                $cpu += 1.0;
                $memory += 1.0;
            }
        }

        return ['cpu' => $cpu, 'memory' => $memory];
    }

    /**
     * Default share for an additional included workload on this project.
     *
     * @return array{cpu: float, memory: float}
     */
    public function defaultIncludedWorkloadShare(): array
    {
        $pool = is_array($this->resource_pool) ? $this->resource_pool : [];

        if (isset($pool['cpu_share'], $pool['memory_share'])) {
            return [
                'cpu' => (float) $pool['cpu_share'],
                'memory' => (float) $pool['memory_share'],
            ];
        }

        $defaults = config('project_recipes.plan_pool.default_workload_share', [
            'cpu' => 0.25,
            'memory' => 0.25,
        ]);

        return [
            'cpu' => (float) ($defaults['cpu'] ?? 0.25),
            'memory' => (float) ($defaults['memory'] ?? 0.25),
        ];
    }

    /**
     * Resolve resource_share for a new included workload, capped by remaining pool.
     *
     * @return array{cpu: float, memory: float}
     */
    public function resolveIncludedWorkloadShare(): array
    {
        $default = $this->defaultIncludedWorkloadShare();
        $allocated = $this->allocatedResourceShares();
        $remainingCpu = max(0.0, 1.0 - $allocated['cpu']);
        $remainingMemory = max(0.0, 1.0 - $allocated['memory']);

        $min = config('project_recipes.plan_pool.min_workload_share', [
            'cpu' => 0.05,
            'memory' => 0.05,
        ]);
        $minCpu = (float) ($min['cpu'] ?? 0.05);
        $minMemory = (float) ($min['memory'] ?? 0.05);

        if ($remainingCpu <= 0 || $remainingMemory <= 0) {
            return [
                'cpu' => $minCpu,
                'memory' => $minMemory,
            ];
        }

        return [
            'cpu' => max($minCpu, min($default['cpu'], $remainingCpu)),
            'memory' => max($minMemory, min($default['memory'], $remainingMemory)),
        ];
    }

    /**
     * @return array{
     *   limits: array{cpu: float, memory_mb: int, disk_gb: float},
     *   allocated_shares: array{cpu: float, memory: float},
     *   remaining_cpu_share: float,
     *   remaining_memory_share: float,
     *   next_workload_share: array{cpu: float, memory: float}
     * }|null
     */
    public function planUsageSummary(): ?array
    {
        $limits = $this->includedPlanLimits();
        if ($limits === null) {
            return null;
        }

        $allocated = $this->allocatedResourceShares();

        return [
            'limits' => $limits,
            'allocated_shares' => $allocated,
            'remaining_cpu_share' => max(0.0, 1.0 - $allocated['cpu']),
            'remaining_memory_share' => max(0.0, 1.0 - $allocated['memory']),
            'next_workload_share' => $this->resolveIncludedWorkloadShare(),
        ];
    }

    public function resourceCount(): int
    {
        return $this->liveServices()->count();
    }
}
