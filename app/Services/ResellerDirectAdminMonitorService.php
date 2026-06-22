<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ResellerDirectAdminMonitorService
{
    private const CHART_DAYS = 14;

    public function __construct(
        private ResellerInfrastructureService $infrastructure,
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerScopeService $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panelData(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage', 'resellerNode');
        $infra = $this->infrastructure->buildDashboard($reseller);
        $customerIds = $this->scope->managedCustomersQuery($reseller)->pluck('id');
        $chart = $this->buildChartSeries($reseller, $customerIds, $infra);

        $paymentsToday = $this->paymentsTotalForDay($customerIds, now());
        $payments7d = $this->paymentsTotalForRange($customerIds, now()->subDays(6)->startOfDay(), now()->endOfDay());
        $payments30d = $this->paymentsTotalForRange($customerIds, now()->subDays(29)->startOfDay(), now()->endOfDay());

        return [
            'is_connected' => $infra['is_connected'],
            'provisioning_ready' => $infra['provisioning_ready'],
            'api_reachable' => $infra['api_reachable'],
            'node_name' => $infra['node']?->name,
            'node_hostname' => $infra['node']?->hostname,
            'directadmin_username' => $infra['directadmin_username'],
            'hosted_user_count' => $infra['hosted_user_count'],
            'max_users' => $infra['max_users'],
            'user_limit_percent' => $infra['user_limit_percent'],
            'disk_used_gb' => $infra['disk_used_gb'],
            'disk_pool_gb' => $infra['disk_pool_gb'],
            'disk_pool_percent' => $infra['disk_pool_percent'],
            'platform_services_on_node' => $infra['platform_services_on_node'],
            'payments_today' => $paymentsToday,
            'payments_7d' => $payments7d,
            'payments_30d' => $payments30d,
            'chart' => $chart,
            'live_url' => route('reseller.dashboard.directadmin-live'),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function liveSnapshot(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage');
        $isConnected = $this->resellerDirectAdmin->hasDirectAdminBinding($reseller);
        $customerIds = $this->scope->managedCustomersQuery($reseller)->pluck('id');

        $hostedUserCount = null;
        $diskUsedGb = null;
        $apiReachable = false;

        if ($isConnected) {
            $hostedUserCount = $this->resellerDirectAdmin->fetchHostedUserCount($reseller);
            $diskMb = $this->resellerDirectAdmin->fetchTotalHostedDiskMb($reseller);
            $diskUsedGb = $diskMb !== null ? round($diskMb / 1024, 2) : null;
            $apiReachable = $hostedUserCount !== null;
        }

        $diskPoolGb = app(ResellerDiskUsageService::class)->diskPoolGb($reseller);
        $diskPoolPercent = ($diskUsedGb !== null && $diskPoolGb > 0)
            ? min(100, round(($diskUsedGb / $diskPoolGb) * 100, 1))
            : null;

        $maxUsers = (int) ($reseller->resellerPackage?->max_users ?? 0);
        $userLimitPercent = ($maxUsers > 0 && $hostedUserCount !== null && $hostedUserCount > 0)
            ? min(100, round(($hostedUserCount / $maxUsers) * 100, 1))
            : null;

        return [
            'is_connected' => $isConnected,
            'api_reachable' => $apiReachable,
            'hosted_user_count' => $hostedUserCount,
            'max_users' => $maxUsers,
            'user_limit_percent' => $userLimitPercent,
            'disk_used_gb' => $diskUsedGb,
            'disk_pool_gb' => $diskPoolGb,
            'disk_pool_percent' => $diskPoolPercent,
            'payments_today' => $this->paymentsTotalForDay($customerIds, now()),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, int>  $customerIds
     * @param  array<string, mixed>  $infra
     * @return array{labels: list<string>, payments: list<float>, disk_gb: list<float|null>, hosted_users: list<int|null>}
     */
    private function buildChartSeries(User $reseller, Collection $customerIds, array $infra): array
    {
        $labels = [];
        $payments = [];
        $diskGb = [];
        $hostedUsers = [];

        $snapshots = ResellerDiskUsageSnapshot::query()
            ->where('reseller_id', $reseller->id)
            ->where('period_date', '>=', now()->subDays(self::CHART_DAYS - 1)->toDateString())
            ->get()
            ->keyBy(fn (ResellerDiskUsageSnapshot $row) => $row->period_date->toDateString());

        $currentHosted = $infra['hosted_user_count'];

        for ($i = self::CHART_DAYS - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $dayKey = $day->toDateString();

            $labels[] = $day->format('M j');
            $payments[] = round($this->paymentsTotalForDay($customerIds, $day), 2);

            $snapshot = $snapshots->get($dayKey);
            $diskGb[] = $snapshot
                ? round((float) $snapshot->directadmin_used_gb, 2)
                : null;

            $hostedUsers[] = ($i === 0 && $currentHosted !== null) ? (int) $currentHosted : null;
        }

        if ($infra['disk_used_gb'] !== null) {
            $diskGb[self::CHART_DAYS - 1] = (float) $infra['disk_used_gb'];
        }

        return [
            'labels' => $labels,
            'payments' => $payments,
            'disk_gb' => $diskGb,
            'hosted_users' => $hostedUsers,
        ];
    }

    /**
     * @param  Collection<int, int>  $customerIds
     */
    private function paymentsTotalForDay(Collection $customerIds, Carbon $day): float
    {
        return $this->paymentsTotalForRange(
            $customerIds,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );
    }

    /**
     * @param  Collection<int, int>  $customerIds
     */
    private function paymentsTotalForRange(Collection $customerIds, Carbon $from, Carbon $to): float
    {
        if ($customerIds->isEmpty()) {
            return 0.0;
        }

        return (float) Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $customerIds->all()))
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($fallback) use ($from, $to) {
                        $fallback->whereNull('paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->sum('amount');
    }
}
