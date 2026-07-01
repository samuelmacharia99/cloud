<?php

namespace App\Services;

use App\Models\ResellerDiskUsageSnapshot;
use App\Models\User;

class ResellerDirectAdminMonitorService
{
    private const CHART_DAYS = 14;

    public function __construct(
        private ResellerInfrastructureService $infrastructure,
        private ResellerDirectAdminService $resellerDirectAdmin,
        private ResellerScopeService $scope,
        private ResellerDashboardPaymentStats $paymentStats,
    ) {}

    /**
     * Lightweight payload for the initial dashboard HTML (no DirectAdmin API calls).
     *
     * @return array<string, mixed>
     */
    public function panelShell(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage', 'resellerNode');
        $isConnected = $this->resellerDirectAdmin->hasDirectAdminBinding($reseller);
        $node = $isConnected ? $this->resellerDirectAdmin->resolveNode($reseller) : null;
        $diskPoolGb = app(ResellerDiskUsageService::class)->diskPoolGb($reseller);
        $maxUsers = (int) ($reseller->resellerPackage?->max_users ?? 0);

        return [
            'is_connected' => $isConnected,
            'connected' => $isConnected,
            'defer_load' => $isConnected,
            'provisioning_ready' => $isConnected ? $this->resellerDirectAdmin->canAutoProvision($reseller) : false,
            'api_reachable' => false,
            'node_name' => $node?->name,
            'node_hostname' => $node?->hostname,
            'directadmin_username' => $reseller->directadmin_username,
            'hosted_user_count' => null,
            'max_users' => $maxUsers,
            'user_limit_percent' => null,
            'disk_used_gb' => null,
            'disk_pool_gb' => $diskPoolGb,
            'disk_pool_percent' => null,
            'platform_services_on_node' => null,
            'payments_today' => null,
            'payments_7d' => null,
            'payments_30d' => null,
            'chart' => $this->emptyChart(),
            'live_url' => route('reseller.dashboard.directadmin-live'),
            'panel_url' => route('reseller.dashboard.directadmin-panel'),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Full panel payload for AJAX load (cached DirectAdmin metrics, consolidated payments).
     *
     * @return array<string, mixed>
     */
    public function panelData(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage', 'resellerNode');
        $infra = $this->infrastructure->buildDashboard($reseller, includePackages: false);
        $customerIds = $this->paymentStats->customerIdsArray(
            $this->scope->managedCustomersQuery($reseller)->pluck('id')
        );
        $chart = $this->buildChartSeries($reseller, $customerIds, $infra);
        $paymentTotals = $this->paymentRangeTotals($customerIds);

        return [
            'is_connected' => $infra['is_connected'],
            'connected' => $infra['is_connected'],
            'defer_load' => false,
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
            'payments_today' => $paymentTotals['today'],
            'payments_7d' => $paymentTotals['7d'],
            'payments_30d' => $paymentTotals['30d'],
            'chart' => $chart,
            'live_url' => route('reseller.dashboard.directadmin-live'),
            'panel_url' => route('reseller.dashboard.directadmin-panel'),
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
        $customerIds = $this->paymentStats->customerIdsArray(
            $this->scope->managedCustomersQuery($reseller)->pluck('id')
        );

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
            'payments_today' => $this->paymentStats->totalForRange(
                $customerIds,
                now()->startOfDay(),
                now()->endOfDay(),
            ),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $customerIds
     * @return array{today: float, 7d: float, 30d: float}
     */
    private function paymentRangeTotals(array $customerIds): array
    {
        $daily = $this->paymentStats->dailyTotals(
            $customerIds,
            now()->subDays(29)->startOfDay(),
            now()->endOfDay(),
        );

        $todayKey = now()->toDateString();
        $today = (float) ($daily[$todayKey] ?? 0);

        $sumDays = function (int $days) use ($daily): float {
            $total = 0.0;
            for ($i = 0; $i < $days; $i++) {
                $key = now()->subDays($i)->toDateString();
                $total += (float) ($daily[$key] ?? 0);
            }

            return round($total, 2);
        };

        return [
            'today' => round($today, 2),
            '7d' => $sumDays(7),
            '30d' => $sumDays(30),
        ];
    }

    /**
     * @param  list<int>  $customerIds
     * @param  array<string, mixed>  $infra
     * @return array{labels: list<string>, payments: list<float>, disk_gb: list<float|null>, hosted_users: list<int|null>}
     */
    private function buildChartSeries(User $reseller, array $customerIds, array $infra): array
    {
        $labels = [];
        $payments = [];
        $diskGb = [];
        $hostedUsers = [];

        $from = now()->subDays(self::CHART_DAYS - 1)->startOfDay();
        $dailyPayments = $this->paymentStats->dailyTotals($customerIds, $from, now()->endOfDay());

        $snapshots = ResellerDiskUsageSnapshot::query()
            ->where('reseller_id', $reseller->id)
            ->where('period_date', '>=', $from->toDateString())
            ->get()
            ->keyBy(fn (ResellerDiskUsageSnapshot $row) => $row->period_date->toDateString());

        $currentHosted = $infra['hosted_user_count'];

        for ($i = self::CHART_DAYS - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $dayKey = $day->toDateString();

            $labels[] = $day->format('M j');
            $payments[] = round((float) ($dailyPayments[$dayKey] ?? 0), 2);

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
     * @return array{labels: list<string>, payments: list<float>, disk_gb: list<float|null>, hosted_users: list<int|null>}
     */
    private function emptyChart(): array
    {
        $labels = [];
        for ($i = self::CHART_DAYS - 1; $i >= 0; $i--) {
            $labels[] = now()->subDays($i)->format('M j');
        }

        return [
            'labels' => $labels,
            'payments' => array_fill(0, self::CHART_DAYS, 0.0),
            'disk_gb' => array_fill(0, self::CHART_DAYS, null),
            'hosted_users' => array_fill(0, self::CHART_DAYS, null),
        ];
    }
}
