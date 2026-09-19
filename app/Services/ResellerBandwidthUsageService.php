<?php

namespace App\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Transfer across a reseller's application hosting stacks, measured from the
 * container metrics samples, against the bandwidth pool on their package.
 * The pool is per month; a longer billing period scales it.
 */
class ResellerBandwidthUsageService
{
    public function __construct(private readonly ResellerScopeService $scope) {}

    public function bandwidthPoolGb(User $reseller): int
    {
        return (int) ($reseller->resellerPackage?->bandwidth_pool_gb ?? 0);
    }

    public function bandwidthOverageRate(User $reseller): float
    {
        $rate = $reseller->resellerPackage?->bandwidth_overage_rate;

        return $rate !== null ? (float) $rate : (float) setting('reseller_bandwidth_overage_rate', 0);
    }

    public function isMetered(User $reseller): bool
    {
        return $this->bandwidthPoolGb($reseller) > 0;
    }

    /**
     * Gigabytes transferred by every managed container between two moments.
     */
    public function transferGbForPeriod(User $reseller, Carbon $from, Carbon $to): float
    {
        $serviceIds = $this->scope->managedServicesQuery($reseller)
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'container')
                    ->orWhereHas('product', fn ($product) => $product->where('type', 'container_hosting'));
            })
            ->pluck('id');
        if ($serviceIds->isEmpty()) {
            return 0.0;
        }

        // Counters are walked per deployment, but read in batches rather than one
        // query per deployment. The arithmetic is unchanged — the batched reader
        // hands each deployment's samples to the same walk — so billed transfer
        // is identical, it is just no longer proportional in queries.
        $bytes = 0;
        ContainerDeployment::query()
            ->whereIn('service_id', $serviceIds)
            ->select(['id', 'service_id'])
            ->chunkById(200, function ($deployments) use (&$bytes, $from, $to) {
                $bytes += array_sum(ContainerMetric::transferBytesForDeployments(
                    $deployments->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $from,
                    $to,
                ));
            });

        return round($bytes / (1024 ** 3), 3);
    }

    /**
     * Month-to-date transfer against the pool, for the dashboard meter.
     *
     * @return array{pool: int, used: float, remaining: float, over: float, percent: ?float, metered: bool, period_start: string}
     */
    public function poolPresentation(User $reseller): array
    {
        $pool = $this->bandwidthPoolGb($reseller);
        $from = now()->startOfMonth();
        $used = $this->transferGbForPeriod($reseller, $from, now());

        return [
            'pool' => $pool,
            'used' => $used,
            'remaining' => $pool > 0 ? round(max(0, $pool - $used), 2) : 0.0,
            'over' => $pool > 0 ? round(max(0, $used - $pool), 2) : 0.0,
            'percent' => $pool > 0 ? min(100, round(($used / $pool) * 100, 1)) : null,
            'metered' => $pool > 0,
            'period_start' => $from->toDateString(),
        ];
    }
}
