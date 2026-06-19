<?php

namespace App\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ResellerDiskUsageService
{
    public function __construct(
        private ResellerScopeService $scope,
    ) {}

    public function diskPoolGb(User $reseller): int
    {
        $package = $reseller->resellerPackage;

        if (! $package) {
            return 0;
        }

        return (int) ($package->disk_pool_gb ?: $package->storage_space ?: 0);
    }

    public function diskOverageRate(User $reseller): float
    {
        $packageRate = $reseller->resellerPackage?->disk_overage_rate;

        if ($packageRate !== null && (float) $packageRate > 0) {
            return (float) $packageRate;
        }

        return (float) setting('reseller_disk_overage_rate', 0);
    }

    /**
     * @return array{directadmin_used_gb: float, container_used_gb: float, total_used_gb: float}
     */
    public function collectCurrentUsage(User $reseller): array
    {
        $directAdminGb = $this->sumDirectAdminDiskGb($reseller);
        $containerGb = $this->sumContainerDiskGb($reseller);

        return [
            'directadmin_used_gb' => round($directAdminGb, 4),
            'container_used_gb' => round($containerGb, 4),
            'total_used_gb' => round($directAdminGb + $containerGb, 4),
        ];
    }

    public function recordDailySnapshot(User $reseller, ?Carbon $date = null): ResellerDiskUsageSnapshot
    {
        $date = ($date ?? now())->toDateString();
        $usage = $this->collectCurrentUsage($reseller);

        return ResellerDiskUsageSnapshot::updateOrCreate(
            [
                'reseller_id' => $reseller->id,
                'period_date' => $date,
            ],
            [
                'directadmin_used_gb' => $usage['directadmin_used_gb'],
                'container_used_gb' => $usage['container_used_gb'],
                'total_used_gb' => $usage['total_used_gb'],
                'recorded_at' => now(),
            ]
        );
    }

    /**
     * @return array{directadmin_used_gb: float, container_used_gb: float, total_used_gb: float, days: int}
     */
    public function averageUsageForPeriod(User $reseller, Carbon $from, Carbon $to): array
    {
        $snapshots = ResellerDiskUsageSnapshot::query()
            ->where('reseller_id', $reseller->id)
            ->whereBetween('period_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        if ($snapshots->isEmpty()) {
            $current = $this->collectCurrentUsage($reseller);

            return [
                'directadmin_used_gb' => $current['directadmin_used_gb'],
                'container_used_gb' => $current['container_used_gb'],
                'total_used_gb' => $current['total_used_gb'],
                'days' => 1,
            ];
        }

        return [
            'directadmin_used_gb' => round((float) $snapshots->avg('directadmin_used_gb'), 4),
            'container_used_gb' => round((float) $snapshots->avg('container_used_gb'), 4),
            'total_used_gb' => round((float) $snapshots->avg('total_used_gb'), 4),
            'days' => $snapshots->count(),
        ];
    }

    public function poolUsagePercent(User $reseller, ?array $usage = null): ?float
    {
        $pool = $this->diskPoolGb($reseller);
        if ($pool <= 0) {
            return null;
        }

        $usage ??= $this->collectCurrentUsage($reseller);

        return min(100, round(($usage['total_used_gb'] / $pool) * 100, 1));
    }

    public function isOverPool(User $reseller, ?array $usage = null): bool
    {
        $pool = $this->diskPoolGb($reseller);
        if ($pool <= 0) {
            return false;
        }

        $usage ??= $this->collectCurrentUsage($reseller);

        return $usage['total_used_gb'] > $pool;
    }

    /**
     * @return Collection<int, User>
     */
    public function resellersWithPackages(): Collection
    {
        return User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->with('resellerPackage')
            ->get();
    }

    private function sumDirectAdminDiskGb(User $reseller): float
    {
        $directAdmin = app(ResellerDirectAdminService::class);
        $accountTotalMb = $directAdmin->fetchTotalHostedDiskMb($reseller);

        if ($accountTotalMb !== null) {
            return $accountTotalMb / 1024;
        }

        return $this->sumPlatformDirectAdminDiskGb($reseller);
    }

    private function sumPlatformDirectAdminDiskGb(User $reseller): float
    {
        $totalMb = 0.0;

        $services = $this->scope->managedServicesQuery($reseller)
            ->with(['node', 'product'])
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'directadmin')
                    ->orWhereHas('product', fn ($product) => $product
                        ->where('provisioning_driver_key', 'directadmin')
                        ->where('type', 'shared_hosting'));
            })
            ->whereIn('status', ['active', 'suspended', 'provisioning'])
            ->get();

        foreach ($services as $service) {
            $usage = $this->resolveDirectAdminUsageMb($service);
            if ($usage !== null) {
                $totalMb += $usage;
            }
        }

        return $totalMb / 1024;
    }

    private function sumContainerDiskGb(User $reseller): float
    {
        $totalGb = 0.0;

        $serviceIds = $this->scope->managedServicesQuery($reseller)
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'container')
                    ->orWhereHas('product', fn ($product) => $product->where('type', 'container_hosting'));
            })
            ->whereIn('status', ['active', 'suspended', 'provisioning'])
            ->pluck('id');

        if ($serviceIds->isEmpty()) {
            return 0.0;
        }

        $deployments = ContainerDeployment::query()
            ->whereIn('service_id', $serviceIds)
            ->get();

        foreach ($deployments as $deployment) {
            $latest = ContainerMetric::query()
                ->where('container_deployment_id', $deployment->id)
                ->orderByDesc('recorded_at')
                ->value('disk_used_gb');

            if ($latest !== null) {
                $totalGb += (float) $latest;

                continue;
            }

            $avg = ContainerMetric::averageDiskUsedGb(
                $deployment,
                now()->subDay(),
                now()
            );

            $totalGb += $avg;
        }

        return $totalGb;
    }

    private function resolveDirectAdminUsageMb(Service $service): ?float
    {
        $meta = $service->service_meta ?? [];
        if (isset($meta['disk_used_mb'])) {
            return (float) $meta['disk_used_mb'];
        }

        $username = $service->external_reference ?? ($meta['username'] ?? null);
        if (blank($username) || ! $service->node) {
            return null;
        }

        $directAdmin = new DirectAdminService($service->node);
        if (! $directAdmin->isConfigured()) {
            return null;
        }

        $usage = $directAdmin->getAccountDiskUsage((string) $username);

        return $usage ? (float) $usage['used_mb'] : null;
    }
}
