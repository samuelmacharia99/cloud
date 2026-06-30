<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

class ResellerAnalyticsService
{
    public function __construct(
        private ResellerScopeService $scope,
        private ResellerCustomerBillingService $billing,
        private ResellerPackageSubscriptionService $packageSubscription,
        private ResellerMarginService $margins,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboardMetrics(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage', 'wallet');

        $managedServices = $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product'])
            ->get();

        $managedCustomers = $this->scope->managedCustomersQuery($reseller)
            ->orderBy('name')
            ->get();

        $customerIds = $managedCustomers->pluck('id');

        $managedInvoices = $this->scope->managedInvoicesQuery($reseller)
            ->with(['user', 'payments'])
            ->get();

        $paidInvoices = $managedInvoices->where('status', InvoiceStatus::Paid);
        $totalRevenue = (float) $paidInvoices->sum('total');
        $outstandingBalance = $this->billing->customerOutstandingTotal($reseller);

        $commissionRate = $this->commissionRate($reseller);
        $totalCommission = $totalRevenue * ($commissionRate / 100);

        $monthlyRevenue = $this->monthlyRevenueSeries($customerIds);
        $invoiceStatus = [
            'paid' => $managedInvoices->where('status', InvoiceStatus::Paid)->count(),
            'unpaid' => $managedInvoices->where('status', InvoiceStatus::Unpaid)->count(),
            'overdue' => $managedInvoices->where('status', InvoiceStatus::Overdue)->count(),
        ];

        $billingHealth = $this->billingHealth($reseller);
        $actionQueue = $this->actionQueue($reseller, $customerIds, $managedInvoices);
        $marginSummary = $this->marginSummary($reseller);
        $diskUsage = app(ResellerDiskUsageService::class);
        $diskPoolGb = $diskUsage->diskPoolGb($reseller);
        $diskUsageSnapshot = $diskUsage->collectCurrentUsage($reseller);
        $diskPoolPercent = $diskUsage->poolUsagePercent($reseller, $diskUsageSnapshot);
        $userCountBreakdown = $reseller->getResellerUserCountBreakdown();
        $ledgerMargin30d = $this->margins->ledgerTotals(
            $reseller,
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
        );

        $directAdminMonitor = app(ResellerDirectAdminMonitorService::class)->panelData($reseller);

        $maxServices = $reseller->resellerPackage?->max_services ?? 0;

        return [
            'resellerPackage' => $reseller->resellerPackage,
            'maxServices' => $maxServices,
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
            'customerCount' => $userCountBreakdown['count'],
            'hostedUserCountSource' => $userCountBreakdown['source'],
            'portalCustomerCount' => $managedCustomers->count(),
            'directAdminDiskIncludesAllUsers' => $reseller->resellerUserCountUsesDirectAdmin(),
            'billingHealth' => $billingHealth,
            'actionQueue' => $actionQueue,
            'marginSummary' => $marginSummary,
            'ledgerMargin30d' => $ledgerMargin30d,
            'registrationInviteUrl' => app(ResellerBrandingResolver::class)->signedRegistrationUrl($reseller),
            'packageExpiresAt' => $reseller->package_expires_at,
            'daysUntilPackageExpiry' => $reseller->package_expires_at
                ? (int) now()->startOfDay()->diffInDays($reseller->package_expires_at->copy()->startOfDay(), false)
                : null,
            'diskPoolGb' => $diskPoolGb,
            'diskUsedGb' => $diskUsageSnapshot['total_used_gb'],
            'diskDirectAdminGb' => $diskUsageSnapshot['directadmin_used_gb'],
            'diskContainerGb' => $diskUsageSnapshot['container_used_gb'],
            'diskPoolPercent' => $diskPoolPercent,
            'directAdminMonitor' => $directAdminMonitor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function marginSummary(User $reseller): array
    {
        $catalogProducts = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->with('adminProduct')
            ->get();

        $monthlyMargins = $catalogProducts
            ->map(fn (ResellerProduct $p) => $p->getMonthlyMargin())
            ->filter(fn ($m) => $m !== null);

        $domainOrders = ResellerDomainOrder::query()
            ->where('reseller_id', $reseller->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('status', ['completed', 'pushed', 'queued'])
            ->get();

        $domainMargin = (float) $domainOrders->sum(fn (ResellerDomainOrder $o) => (float) $o->retail_amount - (float) $o->wholesale_amount);

        return [
            'catalog_count' => $catalogProducts->count(),
            'avg_monthly_margin' => $monthlyMargins->isNotEmpty() ? round($monthlyMargins->avg(), 2) : null,
            'domain_orders_30d' => $domainOrders->count(),
            'domain_margin_30d' => round($domainMargin, 2),
        ];
    }

    public function commissionRate(User $reseller): float
    {
        $rate = $reseller->commission_rate;

        return $rate !== null ? (float) $rate : 20.0;
    }

    /**
     * @return array<string, mixed>
     */
    private function billingHealth(User $reseller): array
    {
        $pendingOwnInvoice = $this->packageSubscription->pendingSubscriptionInvoice($reseller);
        $status = 'active';
        $message = 'Your reseller account is in good standing.';
        $severity = 'success';

        if ($reseller->isResellerSuspended()) {
            $status = 'suspended';
            $message = 'Your account is suspended. Pay your subscription invoice or contact support.';
            $severity = 'danger';
        } elseif ($pendingOwnInvoice) {
            $status = 'billing_due';
            $message = 'You have an unpaid platform subscription invoice.';
            $severity = 'warning';
        } elseif ($reseller->package_expires_at && $reseller->package_expires_at->isPast()) {
            $status = 'expired';
            $message = 'Your reseller package has expired.';
            $severity = 'danger';
        } elseif ($reseller->package_expires_at && $reseller->package_expires_at->lte(now()->addDays(7))) {
            $status = 'expiring_soon';
            $message = 'Your package expires soon. Renew to avoid interruption.';
            $severity = 'warning';
        } elseif (! $reseller->reseller_package_id) {
            $status = 'no_package';
            $message = 'Subscribe to a reseller package to unlock provisioning.';
            $severity = 'warning';
        }

        return [
            'status' => $status,
            'message' => $message,
            'severity' => $severity,
            'pending_own_invoice' => $pendingOwnInvoice,
            'pending_own_invoice_url' => $pendingOwnInvoice
                ? route('reseller.invoices.show', $pendingOwnInvoice)
                : null,
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $managedInvoices
     * @return list<array{label: string, count: int, url: string, severity: string}>
     */
    private function actionQueue(User $reseller, Collection $customerIds, Collection $managedInvoices): array
    {
        $queue = [];

        $overdueCount = $managedInvoices
            ->filter(fn ($inv) => in_array($inv->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)
                && $inv->getAmountRemaining() > 0
                && $inv->due_date && $inv->due_date->isPast())
            ->count();

        if ($overdueCount > 0) {
            $queue[] = [
                'label' => "{$overdueCount} overdue customer invoice(s)",
                'count' => $overdueCount,
                'url' => route('reseller.customer-invoices.index', ['status' => 'overdue']),
                'severity' => 'danger',
            ];
        }

        $unpaidCount = $managedInvoices
            ->filter(fn ($inv) => $inv->status === InvoiceStatus::Unpaid && $inv->getAmountRemaining() > 0)
            ->count();

        if ($unpaidCount > 0 && $overdueCount < $unpaidCount) {
            $queue[] = [
                'label' => "{$unpaidCount} unpaid customer invoice(s)",
                'count' => $unpaidCount,
                'url' => route('reseller.customer-invoices.index', ['status' => 'unpaid']),
                'severity' => 'warning',
            ];
        }

        $openTickets = Ticket::query()
            ->whereIn('user_id', $customerIds->all() ?: [0])
            ->where('status', '!=', 'closed')
            ->count();

        if ($openTickets > 0) {
            $queue[] = [
                'label' => "{$openTickets} open support ticket(s)",
                'count' => $openTickets,
                'url' => route('reseller.tickets.index'),
                'severity' => 'info',
            ];
        }

        $failedOrders = ResellerDomainOrder::query()
            ->forManagedCustomers($reseller)
            ->whereIn('status', ['failed', 'queued'])
            ->count();

        if ($failedOrders > 0) {
            $queue[] = [
                'label' => "{$failedOrders} domain order(s) need attention",
                'count' => $failedOrders,
                'url' => route('reseller.domain-orders.index'),
                'severity' => 'warning',
            ];
        }

        $expiringDomains = $this->expiringDomainsCount($reseller, $customerIds);

        if ($expiringDomains > 0) {
            $queue[] = [
                'label' => "{$expiringDomains} domain(s) expiring within 30 days",
                'count' => $expiringDomains,
                'url' => route('reseller.domains.index'),
                'severity' => 'info',
            ];
        }

        $diskUsage = app(ResellerDiskUsageService::class);
        $poolGb = $diskUsage->diskPoolGb($reseller);
        if ($poolGb > 0) {
            $usage = $diskUsage->collectCurrentUsage($reseller);
            $percent = $diskUsage->poolUsagePercent($reseller, $usage);
            if ($percent !== null && $percent >= 90) {
                $queue[] = [
                    'label' => sprintf('Disk pool at %s%% (%s / %s GB)', rtrim(rtrim(number_format($percent, 1), '0'), '.'), number_format($usage['total_used_gb'], 1), $poolGb),
                    'count' => 1,
                    'url' => route('reseller.wallet.index'),
                    'severity' => $percent >= 100 ? 'danger' : 'warning',
                ];
            }
        }

        $suspendedServicesCount = $this->scope->managedServicesQuery($reseller)
            ->where('status', ServiceStatus::Suspended)
            ->count();

        if ($suspendedServicesCount > 0) {
            $queue[] = [
                'label' => "{$suspendedServicesCount} suspended customer service(s)",
                'count' => $suspendedServicesCount,
                'url' => route('reseller.services.index', ['status' => 'suspended']),
                'severity' => 'warning',
            ];
        }

        if ($reseller->directadmin_username && app(ResellerDirectAdminService::class)->hasDirectAdminBinding($reseller)) {
            $reconciliation = app(ResellerHostedAccountReconciliationService::class)->reconcileReseller($reseller);
            $unlinked = (int) ($reconciliation['unlinked_count'] ?? 0);

            if ($unlinked > 0) {
                $queue[] = [
                    'label' => "{$unlinked} DirectAdmin account(s) not linked to the platform",
                    'count' => $unlinked,
                    'url' => route('reseller.customers.index', ['link' => 'unlinked']),
                    'severity' => 'warning',
                ];
            }
        }

        return $queue;
    }

    private function expiringDomainsCount(User $reseller, Collection $customerIds): int
    {
        $customerIdsFromServices = Service::where('reseller_id', $reseller->id)
            ->distinct()
            ->pluck('user_id');

        $ids = $customerIds->merge($customerIdsFromServices)->unique()->filter();

        return Domain::query()
            ->where(function ($q) use ($reseller, $ids) {
                $q->where('user_id', $reseller->id)
                    ->orWhereIn('user_id', $ids->all() ?: [0])
                    ->orWhere('reseller_id', $reseller->id);
            })
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->count();
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
                ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $ids ?: [0]))
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');
        }

        return $series;
    }
}
