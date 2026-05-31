<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

class ResellerAnalyticsService
{
    public function __construct(
        private ResellerScopeService $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboardMetrics(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage');

        $managedServices = $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product'])
            ->get();

        $managedCustomers = $this->scope->managedCustomersQuery($reseller)->get();
        $customerIds = $managedCustomers->pluck('id');

        $managedInvoices = $this->scope->managedInvoicesQuery($reseller)->get();
        $paidInvoices = $managedInvoices->where('status', 'paid');
        $totalRevenue = (float) $paidInvoices->sum('total');
        $outstandingBalance = (float) $managedInvoices->whereIn('status', ['unpaid', 'overdue'])->sum('total');

        $commissionRate = $this->commissionRate($reseller);
        $totalCommission = $totalRevenue * ($commissionRate / 100);

        $monthlyRevenue = $this->monthlyRevenueSeries($customerIds);
        $invoiceStatus = [
            'paid' => $managedInvoices->where('status', 'paid')->count(),
            'unpaid' => $managedInvoices->where('status', 'unpaid')->count(),
            'overdue' => $managedInvoices->where('status', 'overdue')->count(),
        ];

        return [
            'resellerPackage' => $reseller->resellerPackage,
            'activeServices' => $managedServices->filter(fn ($service) => $service->status === ServiceStatus::Active)->count(),
            'suspendedServices' => $managedServices->filter(fn ($service) => $service->status === ServiceStatus::Suspended)->count(),
            'totalServices' => $managedServices->count(),
            'managedCustomers' => $managedCustomers,
            'managedServices' => $managedServices->sortByDesc('created_at')->take(8)->values(),
            'totalRevenue' => $totalRevenue,
            'outstandingBalance' => $outstandingBalance,
            'commissionRate' => $commissionRate,
            'totalCommission' => $totalCommission,
            'recentInvoices' => $managedInvoices->sortByDesc('created_at')->take(5)->values(),
            'monthlyRevenue' => $monthlyRevenue,
            'invoiceStatus' => $invoiceStatus,
            'customerCount' => $managedCustomers->count(),
        ];
    }

    public function commissionRate(User $reseller): float
    {
        $rate = $reseller->commission_rate;

        return $rate !== null ? (float) $rate : 20.0;
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $customerIds
     * @return list<float>
     */
    private function monthlyRevenueSeries(Collection|array $customerIds): array
    {
        $ids = $customerIds instanceof Collection ? $customerIds->all() : $customerIds;
        $series = [];

        for ($i = 5; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end = now()->subMonths($i)->endOfMonth();

            $series[] = (float) Payment::query()
                ->where('status', 'completed')
                ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $ids))
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');
        }

        return $series;
    }
}
