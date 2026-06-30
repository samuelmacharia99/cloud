<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

        $servicesQuery = $this->scope->managedServicesQuery($reseller);
        $invoicesQuery = $this->scope->managedInvoicesQuery($reseller);
        $customerIds = $this->scope->managedCustomerIds($reseller);

        $serviceCounts = (clone $servicesQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $activeServices = (int) ($serviceCounts[ServiceStatus::Active->value] ?? $serviceCounts['active'] ?? 0);
        $suspendedServices = (int) ($serviceCounts[ServiceStatus::Suspended->value] ?? $serviceCounts['suspended'] ?? 0);
        $totalServices = (int) $serviceCounts->sum();

        $invoiceStatus = [
            'paid' => (clone $invoicesQuery)->where('status', InvoiceStatus::Paid)->count(),
            'unpaid' => (clone $invoicesQuery)->where('status', InvoiceStatus::Unpaid)->count(),
            'overdue' => (clone $invoicesQuery)->where('status', InvoiceStatus::Overdue)->count(),
        ];

        $totalRevenue = (float) (clone $invoicesQuery)->where('status', InvoiceStatus::Paid)->sum('total');
        $revenue30d = (float) Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $customerIds ?: [0]))
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        $outstandingBalance = $this->billing->customerOutstandingTotal($reseller);
        $userCountBreakdown = $reseller->getResellerUserCountBreakdown();
        $portalCustomerCount = $this->scope->managedCustomerCount($reseller);

        $billingHealth = $this->billingHealthSnapshot($reseller);
        $diskUsage = app(ResellerDiskUsageService::class);
        $diskPoolGb = $diskUsage->diskPoolGb($reseller);
        $diskUsageSnapshot = $diskUsage->collectCurrentUsage($reseller);
        $diskPoolPercent = $diskUsage->poolUsagePercent($reseller, $diskUsageSnapshot);

        $directAdminMonitor = app(ResellerDirectAdminMonitorService::class)->panelData($reseller);
        $daBinding = app(ResellerDirectAdminService::class);
        $hasDa = $daBinding->hasDirectAdminBinding($reseller);
        $unlinkedDaCount = $hasDa ? $this->cachedUnlinkedDirectAdminCount($reseller) : 0;

        $maxServices = $reseller->resellerPackage?->max_services ?? 0;
        $onboarding = $this->onboardingChecklist($reseller, $hasDa, $unlinkedDaCount);

        return [
            'resellerPackage' => $reseller->resellerPackage,
            'maxServices' => $maxServices,
            'activeServices' => $activeServices,
            'suspendedServices' => $suspendedServices,
            'totalServices' => $totalServices,
            'managedCustomers' => $this->scope->managedCustomersQuery($reseller)->orderBy('name')->limit(6)->get(),
            'managedServices' => (clone $servicesQuery)->with(['user', 'product'])->latest()->limit(8)->get(),
            'totalRevenue' => $totalRevenue,
            'revenue30d' => $revenue30d,
            'outstandingBalance' => $outstandingBalance,
            'recentInvoices' => (clone $invoicesQuery)->with(['user', 'payments'])->latest()->limit(5)->get(),
            'monthlyRevenue' => $this->monthlyRevenueSeries($customerIds),
            'invoiceStatus' => $invoiceStatus,
            'customerCount' => $userCountBreakdown['count'],
            'hostedUserCountSource' => $userCountBreakdown['source'],
            'portalCustomerCount' => $portalCustomerCount,
            'unlinkedDaCount' => $unlinkedDaCount,
            'hasDirectAdmin' => $hasDa,
            'directAdminDiskIncludesAllUsers' => $reseller->resellerUserCountUsesDirectAdmin(),
            'billingHealth' => $billingHealth,
            'actionQueue' => $this->actionQueue($reseller, $customerIds, $unlinkedDaCount, $billingHealth),
            'activityFeed' => $this->activityFeed($reseller, $customerIds),
            'onboarding' => $onboarding,
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
            'showStaticDiskBars' => ! ($directAdminMonitor['connected'] ?? false),
        ];
    }

    /**
     * Lightweight billing health for layout sidebar (no heavy queries).
     *
     * @return array<string, mixed>
     */
    public function billingHealthSnapshot(User $reseller): array
    {
        $reseller->loadMissing('resellerPackage');

        $pendingOwnInvoice = $this->packageSubscription->pendingSubscriptionInvoice($reseller);
        $status = 'active';
        $message = 'Your reseller account is in good standing.';
        $severity = 'success';
        $sidebarLabel = 'Account active';

        if ($reseller->isResellerSuspended()) {
            $status = 'suspended';
            $message = 'Your account is suspended. Pay your subscription invoice or contact support.';
            $severity = 'danger';
            $sidebarLabel = 'Account suspended';
        } elseif ($pendingOwnInvoice) {
            $status = 'billing_due';
            $message = 'You have an unpaid platform subscription invoice.';
            $severity = 'warning';
            $sidebarLabel = 'Subscription due';
        } elseif ($reseller->package_expires_at && $reseller->package_expires_at->isPast()) {
            $status = 'expired';
            $message = 'Your reseller package has expired.';
            $severity = 'danger';
            $sidebarLabel = 'Package expired';
        } elseif ($reseller->package_expires_at && $reseller->package_expires_at->lte(now()->addDays(7))) {
            $status = 'expiring_soon';
            $message = 'Your package expires soon. Renew to avoid interruption.';
            $severity = 'warning';
            $sidebarLabel = 'Renewal soon';
        } elseif (! $reseller->reseller_package_id) {
            $status = 'no_package';
            $message = 'Subscribe to a reseller package to unlock provisioning.';
            $severity = 'warning';
            $sidebarLabel = 'No package';
        }

        return [
            'status' => $status,
            'message' => $message,
            'severity' => $severity,
            'sidebar_label' => $sidebarLabel,
            'pending_own_invoice' => $pendingOwnInvoice,
            'pending_own_invoice_url' => $pendingOwnInvoice
                ? route('reseller.invoices.show', $pendingOwnInvoice)
                : null,
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
     * @return array{steps: list<array<string, mixed>>, completed: int, total: int, is_complete: bool}
     */
    public function onboardingChecklist(User $reseller, ?bool $hasDa = null, ?int $unlinkedDaCount = null): array
    {
        $settings = app(ResellerSettingsService::class);
        $mpesa = $settings->getMpesaSettings($reseller);
        $branding = $settings->getBrandingSettings($reseller);
        $hasDa ??= app(ResellerDirectAdminService::class)->hasDirectAdminBinding($reseller);
        $unlinkedDaCount ??= $hasDa ? $this->cachedUnlinkedDirectAdminCount($reseller) : 0;

        $hostingCatalogCount = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->count();

        $steps = [
            [
                'key' => 'package',
                'label' => 'Subscribe to a reseller package',
                'done' => (bool) $reseller->reseller_package_id,
                'url' => route('reseller.packages.index'),
            ],
            [
                'key' => 'branding',
                'label' => 'Configure branding and signup link',
                'done' => filled($branding['company_name'] ?? null) || filled($branding['custom_domain'] ?? null),
                'url' => route('reseller.settings.index', ['tab' => 'branding']),
            ],
            [
                'key' => 'payments',
                'label' => 'Set up customer payment (M-Pesa)',
                'done' => filled($mpesa['business_shortcode'] ?? null),
                'url' => route('reseller.settings.index', ['tab' => 'payment']),
            ],
            [
                'key' => 'catalog',
                'label' => 'Add shared hosting to your catalog',
                'done' => $hostingCatalogCount > 0,
                'url' => route('reseller.catalog.index'),
            ],
            [
                'key' => 'directadmin',
                'label' => 'Connect DirectAdmin (via platform admin)',
                'done' => $hasDa,
                'url' => route('reseller.settings.index', ['tab' => 'hosting']),
            ],
            [
                'key' => 'link_accounts',
                'label' => 'Link all DirectAdmin users to the platform',
                'done' => ! $hasDa || $unlinkedDaCount === 0,
                'url' => route('reseller.customers.index', ['link' => 'unlinked']),
                'optional' => ! $hasDa,
            ],
        ];

        $required = collect($steps)->reject(fn ($step) => ($step['optional'] ?? false) && ! $hasDa);
        $completed = $required->filter(fn ($step) => $step['done'])->count();

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $required->count(),
            'is_complete' => $completed >= $required->count(),
        ];
    }

    /**
     * @param  list<int>  $customerIds
     * @return list<array{type: string, title: string, subtitle: ?string, url: string, at: string}>
     */
    public function activityFeed(User $reseller, array $customerIds): array
    {
        $items = collect();

        $recentInvoices = $this->scope->managedInvoicesQuery($reseller)
            ->with('user')
            ->latest()
            ->limit(5)
            ->get();

        foreach ($recentInvoices as $invoice) {
            $items->push([
                'type' => 'invoice',
                'title' => "Invoice {$invoice->invoice_number}",
                'subtitle' => ($invoice->user?->name ?? 'Customer').' · KSH '.number_format((float) $invoice->total, 2),
                'url' => route('reseller.customer-invoices.show', $invoice),
                'at' => $invoice->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'sort' => $invoice->created_at?->timestamp ?? 0,
            ]);
        }

        $recentServices = $this->scope->managedServicesQuery($reseller)
            ->with('user')
            ->latest()
            ->limit(5)
            ->get();

        foreach ($recentServices as $service) {
            $items->push([
                'type' => 'service',
                'title' => $service->name ?? 'Service',
                'subtitle' => $service->user?->name,
                'url' => route('reseller.services.show', $service),
                'at' => $service->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'sort' => $service->created_at?->timestamp ?? 0,
            ]);
        }

        $recentOrders = ResellerDomainOrder::query()
            ->forManagedCustomers($reseller)
            ->latest()
            ->limit(4)
            ->get();

        foreach ($recentOrders as $order) {
            $items->push([
                'type' => 'domain_order',
                'title' => "Domain order: {$order->domain}",
                'subtitle' => ucfirst((string) $order->status),
                'url' => route('reseller.domain-orders.index'),
                'at' => $order->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'sort' => $order->created_at?->timestamp ?? 0,
            ]);
        }

        if ($customerIds !== []) {
            $recentTickets = Ticket::query()
                ->whereIn('user_id', $customerIds)
                ->latest()
                ->limit(3)
                ->get();

            foreach ($recentTickets as $ticket) {
                $items->push([
                    'type' => 'ticket',
                    'title' => $ticket->subject,
                    'subtitle' => ucfirst((string) $ticket->status),
                    'url' => route('reseller.tickets.show', $ticket),
                    'at' => $ticket->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'sort' => $ticket->created_at?->timestamp ?? 0,
                ]);
            }
        }

        return $items->sortByDesc('sort')->take(12)->values()->all();
    }

    private function cachedUnlinkedDirectAdminCount(User $reseller): int
    {
        return (int) Cache::remember(
            'reseller_da_unlinked_count:'.$reseller->id,
            300,
            function () use ($reseller) {
                $result = app(ResellerHostedAccountReconciliationService::class)->reconcileReseller($reseller);

                return (int) ($result['unlinked_count'] ?? 0);
            },
        );
    }

    /**
     * @param  list<int>  $customerIds
     * @param  array<string, mixed>  $billingHealth
     * @return list<array{label: string, count: int, url: string, severity: string}>
     */
    private function actionQueue(User $reseller, array $customerIds, int $unlinkedDaCount, array $billingHealth): array
    {
        $queue = [];
        $customerIdCollection = collect($customerIds);
        $invoicesQuery = $this->scope->managedInvoicesQuery($reseller);

        if (($billingHealth['status'] ?? '') === 'billing_due' && ! empty($billingHealth['pending_own_invoice_url'])) {
            $queue[] = [
                'label' => 'Platform subscription invoice is unpaid',
                'count' => 1,
                'url' => $billingHealth['pending_own_invoice_url'],
                'severity' => 'danger',
            ];
        }

        if ($reseller->wallet?->isLowBalance()) {
            $queue[] = [
                'label' => 'Wallet balance is low — top up for domains and wholesale orders',
                'count' => 1,
                'url' => route('reseller.wallet.index'),
                'severity' => 'warning',
            ];
        }

        $overdueCount = (clone $invoicesQuery)
            ->where(function ($query) {
                $query->where('status', InvoiceStatus::Overdue)
                    ->orWhere(function ($unpaid) {
                        $unpaid->where('status', InvoiceStatus::Unpaid)
                            ->where('due_date', '<', now());
                    });
            })
            ->count();

        if ($overdueCount > 0) {
            $queue[] = [
                'label' => "{$overdueCount} overdue customer invoice(s)",
                'count' => $overdueCount,
                'url' => route('reseller.customer-invoices.index', ['status' => 'overdue']),
                'severity' => 'danger',
            ];
        }

        $unpaidCount = (clone $invoicesQuery)->where('status', InvoiceStatus::Unpaid)->count();

        if ($unpaidCount > 0 && $overdueCount < $unpaidCount) {
            $queue[] = [
                'label' => "{$unpaidCount} unpaid customer invoice(s)",
                'count' => $unpaidCount,
                'url' => route('reseller.customer-invoices.index', ['status' => 'unpaid']),
                'severity' => 'warning',
            ];
        }

        $openTickets = Ticket::query()
            ->whereIn('user_id', $customerIdCollection->all() ?: [0])
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

        $expiringDomains = $this->expiringDomainsCount($reseller, $customerIdCollection);

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
                    'url' => route('reseller.packages.index'),
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

        if ($unlinkedDaCount > 0) {
            $queue[] = [
                'label' => "{$unlinkedDaCount} DirectAdmin account(s) not linked to the platform",
                'count' => $unlinkedDaCount,
                'url' => route('reseller.customers.index', ['link' => 'unlinked']),
                'severity' => 'warning',
            ];
        }

        $catalogWithoutDaPackage = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->whereNull('direct_admin_package_name')
            ->count();

        if ($catalogWithoutDaPackage > 0 && app(ResellerDirectAdminService::class)->hasDirectAdminBinding($reseller)) {
            $queue[] = [
                'label' => "{$catalogWithoutDaPackage} catalog item(s) missing DirectAdmin package mapping",
                'count' => $catalogWithoutDaPackage,
                'url' => route('reseller.catalog.index'),
                'severity' => 'info',
            ];
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
     * @param  list<int>  $customerIds
     * @return list<float>
     */
    private function monthlyRevenueSeries(array $customerIds): array
    {
        $series = [];

        for ($i = 5; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end = now()->subMonths($i)->endOfMonth();

            $series[] = (float) Payment::query()
                ->where('status', 'completed')
                ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $customerIds ?: [0]))
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');
        }

        return $series;
    }
}
