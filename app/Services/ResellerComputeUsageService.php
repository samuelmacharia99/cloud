<?php

namespace App\Services;

use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The CPU and memory a reseller's application plans have committed.
 *
 * Deliberately a sibling of ResellerDiskUsageService rather than part of it.
 * Disk is measured: metric samples and live DirectAdmin calls, which cost
 * network time to read. Compute is contracted: the limits written onto each
 * deployment when it was built. Merging the two would force a DirectAdmin
 * round trip every time somebody wanted a CPU number.
 *
 * A pool of zero means unmetered, matching the disk service exactly: percent is
 * null, nothing is enforced and no meter renders. Every package starts there,
 * so this feature is inert until an operator sets a number.
 *
 * This pool is container-only. No per-account CPU or memory allocation exists
 * for DirectAdmin anywhere in the platform, so there is nothing honest to add.
 */
class ResellerComputeUsageService
{
    /**
     * Service states that hold capacity on a node.
     *
     * Mirrors ResellerDiskUsageService::sumContainerDiskGb() so both pools
     * measure the same population. `pending` is absent from both, which means
     * unpaid orders are not counted until they provision; the provision-time
     * check is what catches a burst of them.
     *
     * @var list<string>
     */
    private const COUNTED_STATUSES = ['active', 'suspended', 'provisioning'];

    public function __construct(private ResellerScopeService $scope) {}

    public function cpuPoolCores(User $reseller): float
    {
        return (float) ($reseller->resellerPackage?->cpu_pool_cores ?? 0);
    }

    public function memoryPoolMb(User $reseller): int
    {
        return (int) ($reseller->resellerPackage?->memory_pool_mb ?? 0);
    }

    public function isMetered(User $reseller): bool
    {
        return $this->cpuPoolCores($reseller) > 0 || $this->memoryPoolMb($reseller) > 0;
    }

    /**
     * @return array{cpu_cores: float, memory_mb: int, service_count: int}
     */
    public function collectCurrentAllocation(User $reseller): array
    {
        $services = $this->countedServices($reseller);

        if ($services->isEmpty()) {
            return ['cpu_cores' => 0.0, 'memory_mb' => 0, 'service_count' => 0];
        }

        $deployments = ContainerDeployment::query()
            ->whereIn('service_id', $services->pluck('id'))
            ->where('status', '!=', 'terminated')
            ->get(['service_id', 'cpu_limit', 'memory_limit_mb']);

        $cpu = 0.0;
        $memory = 0;
        $deploymentsByService = $deployments->keyBy('service_id');

        foreach ($services as $service) {
            $deployment = $deploymentsByService->get($service->id);

            // A service that is still provisioning may have no deployment row
            // yet. Its plan is already sold, so it counts, using the same
            // precedence the deployment will be built from.
            $limits = $deployment
                ? ['cpu_limit' => (float) $deployment->cpu_limit, 'memory_limit_mb' => (int) $deployment->memory_limit_mb]
                : app(ContainerDeploymentService::class)->containerResourceLimitsForService($service);

            $cpu += (float) ($limits['cpu_limit'] ?? 0);
            $memory += (int) ($limits['memory_limit_mb'] ?? 0);
        }

        return [
            'cpu_cores' => round($cpu, 2),
            'memory_mb' => $memory,
            'service_count' => $services->count(),
        ];
    }

    /**
     * @param  array{cpu_cores?: float, memory_mb?: int, service_count?: int}|null  $allocation
     * @return array{cpu: array{pool: float, used: float, remaining: float, over: float, percent: ?float}, memory: array{pool: int, used: int, remaining: int, over: int, percent: ?float}, service_count: int, metered: bool}
     */
    public function poolPresentation(User $reseller, ?array $allocation = null): array
    {
        $allocation ??= $this->collectCurrentAllocation($reseller);
        $cpuPool = $this->cpuPoolCores($reseller);
        $memoryPool = $this->memoryPoolMb($reseller);
        $cpuUsed = (float) ($allocation['cpu_cores'] ?? 0);
        $memoryUsed = (int) ($allocation['memory_mb'] ?? 0);

        return [
            'cpu' => [
                'pool' => $cpuPool,
                'used' => round($cpuUsed, 2),
                'remaining' => $cpuPool > 0 ? round(max(0, $cpuPool - $cpuUsed), 2) : 0.0,
                'over' => $cpuPool > 0 ? round(max(0, $cpuUsed - $cpuPool), 2) : 0.0,
                'percent' => $this->percent($cpuUsed, $cpuPool),
            ],
            'memory' => [
                'pool' => $memoryPool,
                'used' => $memoryUsed,
                'remaining' => $memoryPool > 0 ? max(0, $memoryPool - $memoryUsed) : 0,
                'over' => $memoryPool > 0 ? max(0, $memoryUsed - $memoryPool) : 0,
                'percent' => $this->percent((float) $memoryUsed, (float) $memoryPool),
            ],
            'service_count' => (int) ($allocation['service_count'] ?? 0),
            'metered' => $this->isMetered($reseller),
        ];
    }

    /**
     * @param  array{cpu_cores?: float, memory_mb?: int}|null  $allocation
     */
    public function isOverPool(User $reseller, ?array $allocation = null): bool
    {
        $presentation = $this->poolPresentation($reseller, $allocation);

        return $presentation['cpu']['over'] > 0 || $presentation['memory']['over'] > 0;
    }

    /**
     * The compute one more plan would commit.
     *
     * @return array{cpu_cores: float, memory_mb: int}
     */
    public function requestedAllocationForListing(?ResellerProduct $listing, ?Product $adminProduct = null): array
    {
        if ($listing?->hasContainerResourceLimits()) {
            $limits = $listing->containerResourceLimits();

            return [
                'cpu_cores' => (float) ($limits['cpu'] ?? 0),
                'memory_mb' => (int) ($limits['memory_mb'] ?? 0),
            ];
        }

        $included = $adminProduct?->getIncludedContainerLimits($adminProduct->containerTemplate) ?? [];

        return [
            'cpu_cores' => (float) ($included['cpu'] ?? 0),
            'memory_mb' => (int) ($included['memory_mb'] ?? 0),
        ];
    }

    /**
     * @return array{allowed: bool, cpu_over: float, memory_over: int}
     */
    public function checkHeadroom(User $reseller, float $cpuCores, int $memoryMb, ?array $allocation = null): array
    {
        if (! $this->isMetered($reseller) || ! $this->enforcementEnabled()) {
            return ['allowed' => true, 'cpu_over' => 0.0, 'memory_over' => 0];
        }

        $current = $this->poolPresentation($reseller, $allocation);
        $cpuPool = $current['cpu']['pool'];
        $memoryPool = $current['memory']['pool'];

        $cpuOver = $cpuPool > 0 ? round(max(0, ($current['cpu']['used'] + $cpuCores) - $cpuPool), 2) : 0.0;
        $memoryOver = $memoryPool > 0 ? max(0, ($current['memory']['used'] + $memoryMb) - $memoryPool) : 0;

        return [
            'allowed' => $cpuOver <= 0 && $memoryOver <= 0,
            'cpu_over' => $cpuOver,
            'memory_over' => $memoryOver,
        ];
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertHeadroom(User $reseller, float $cpuCores, int $memoryMb): void
    {
        $headroom = $this->checkHeadroom($reseller, $cpuCores, $memoryMb);

        if ($headroom['allowed']) {
            return;
        }

        $shortfall = [];
        if ($headroom['cpu_over'] > 0) {
            $shortfall[] = rtrim(rtrim(number_format($headroom['cpu_over'], 2), '0'), '.').' vCPU';
        }
        if ($headroom['memory_over'] > 0) {
            $shortfall[] = number_format($headroom['memory_over'] / 1024, 1).' GB RAM';
        }

        throw new \InvalidArgumentException(
            'This plan needs '.implode(' and ', $shortfall).' more than your package pool has left. '
            .'Upgrade your package or free capacity by terminating an unused service.'
        );
    }

    public function recordDailySnapshot(User $reseller, ?Carbon $date = null): ResellerDiskUsageSnapshot
    {
        $allocation = $this->collectCurrentAllocation($reseller);
        // Carbon at midnight, matching the shape the date cast stores; see
        // ResellerDiskUsageService::recordDailySnapshot().
        $date = ($date ?? now())->startOfDay();

        // A merge, never a replace: the disk collector writes its own columns on
        // the same row for the same day, in either order.
        return ResellerDiskUsageSnapshot::updateOrCreate(
            ['reseller_id' => $reseller->id, 'period_date' => $date],
            [
                'cpu_cores_allocated' => $allocation['cpu_cores'],
                'memory_mb_allocated' => $allocation['memory_mb'],
                'recorded_at' => now(),
            ],
        );
    }

    /**
     * @return Collection<int, Service>
     */
    private function countedServices(User $reseller): Collection
    {
        return $this->scope->managedServicesQuery($reseller)
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'container')
                    ->orWhereHas('product', fn ($product) => $product->where('type', 'container_hosting'));
            })
            ->whereIn('status', self::COUNTED_STATUSES)
            ->with('product.containerTemplate')
            ->get();
    }

    private function percent(float $used, float $pool): ?float
    {
        return $pool > 0 ? min(100, round(($used / $pool) * 100, 1)) : null;
    }

    private function enforcementEnabled(): bool
    {
        $value = strtolower((string) setting('reseller_enforce_compute_pool', 'true'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
