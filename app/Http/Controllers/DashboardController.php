<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\ResellerAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard();
        }

        if ($user->is_reseller) {
            return $this->resellerDashboard($user);
        }

        return $this->customerDashboard($user);
    }

    private function adminDashboard()
    {
        // Key Metrics — customers only (exclude reseller accounts)
        $platformCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNull('reseller_id')
            ->count();
        $resellerManagedCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNotNull('reseller_id')
            ->count();
        $totalResellers = User::where('is_reseller', true)->count();
        $totalCustomers = $platformCustomers + $resellerManagedCustomers;
        $serviceStatusCounts = Service::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['active', 'suspended', 'terminated', 'cancelled'])
            ->groupBy('status')
            ->pluck('count', 'status');
        $invoiceStatusCounts = Invoice::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['unpaid', 'paid', 'overdue', 'cancelled'])
            ->groupBy('status')
            ->pluck('count', 'status');
        $invoiceStatusSums = Invoice::query()
            ->select('status', DB::raw('SUM(total) as total'))
            ->whereIn('status', ['unpaid', 'overdue'])
            ->groupBy('status')
            ->pluck('total', 'status');
        $paymentStatusSums = Payment::query()
            ->select('status', DB::raw('SUM(amount) as total'))
            ->whereIn('status', ['completed', 'pending'])
            ->groupBy('status')
            ->pluck('total', 'status');

        $activeServices = (int) ($serviceStatusCounts['active'] ?? 0);
        $totalServices = (int) $serviceStatusCounts->sum();
        $suspendedServices = (int) ($serviceStatusCounts['suspended'] ?? 0);
        $unpaidInvoiceTotal = (float) ($invoiceStatusSums['unpaid'] ?? 0);
        $overdueInvoiceTotal = (float) ($invoiceStatusSums['overdue'] ?? 0);
        $totalRevenue = (float) ($paymentStatusSums['completed'] ?? 0);
        $pendingPayments = (float) ($paymentStatusSums['pending'] ?? 0);
        $openTickets = Ticket::visibleToAdmin()->where('status', '!=', 'closed')->count();
        $urgentTickets = Ticket::visibleToAdmin()->where('status', '!=', 'closed')->where('priority', 'urgent')->count();

        // Recent Activity
        $recentCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->with('reseller:id,name')
            ->latest()
            ->take(8)
            ->get();
        $recentServices = Service::with('user', 'product')->latest()->take(8)->get();
        $recentInvoices = Invoice::with('user')->latest()->take(8)->get();
        $recentPayments = Payment::with('user', 'invoice')->latest()->take(8)->get();
        $openTickets_data = Ticket::visibleToAdmin()->with('user', 'assignee')->where('status', '!=', 'closed')->latest()->take(8)->get();

        // Status Breakdown
        $serviceStatus = [
            'active' => (int) ($serviceStatusCounts['active'] ?? 0),
            'suspended' => (int) ($serviceStatusCounts['suspended'] ?? 0),
            'terminated' => (int) ($serviceStatusCounts['terminated'] ?? 0),
            'cancelled' => (int) ($serviceStatusCounts['cancelled'] ?? 0),
        ];

        $invoiceStatus = [
            'unpaid' => (int) ($invoiceStatusCounts['unpaid'] ?? 0),
            'paid' => (int) ($invoiceStatusCounts['paid'] ?? 0),
            'overdue' => (int) ($invoiceStatusCounts['overdue'] ?? 0),
            'cancelled' => (int) ($invoiceStatusCounts['cancelled'] ?? 0),
        ];

        // Revenue Data (last 30 days)
        $revenueData = [];
        $thirtyDayStart = now()->subDays(29)->startOfDay();
        $dailyRevenue = Payment::query()
            ->selectRaw('DATE(created_at) as day, SUM(amount) as total')
            ->where('status', 'completed')
            ->where('created_at', '>=', $thirtyDayStart)
            ->groupBy('day')
            ->pluck('total', 'day');
        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $revenueData[] = (float) ($dailyRevenue[$day] ?? 0);
        }

        // Top Products
        $topProducts = Product::withCount('services')
            ->orderBy('services_count', 'desc')
            ->take(5)
            ->get();

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        // Recent Signups (last 7 days)
        $signupData = [];
        $signupStart = now()->subDays(6)->startOfDay();
        $dailySignups = User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->where('created_at', '>=', $signupStart)
            ->groupBy('day')
            ->pluck('total', 'day');
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $signupData[] = (int) ($dailySignups[$day] ?? 0);
        }

        return view('dashboard.admin', [
            // Metrics
            'totalCustomers' => $totalCustomers,
            'platformCustomers' => $platformCustomers,
            'resellerManagedCustomers' => $resellerManagedCustomers,
            'totalResellers' => $totalResellers,
            'activeServices' => $activeServices,
            'totalServices' => $totalServices,
            'suspendedServices' => $suspendedServices,
            'unpaidInvoiceTotal' => $unpaidInvoiceTotal,
            'overdueInvoiceTotal' => $overdueInvoiceTotal,
            'totalRevenue' => $totalRevenue,
            'pendingPayments' => $pendingPayments,
            'openTickets' => $openTickets,
            'urgentTickets' => $urgentTickets,

            // Recent Activity
            'recentCustomers' => $recentCustomers,
            'recentServices' => $recentServices,
            'recentInvoices' => $recentInvoices,
            'recentPayments' => $recentPayments,
            'openTickets_data' => $openTickets_data,

            // Status Breakdowns
            'serviceStatus' => $serviceStatus,
            'invoiceStatus' => $invoiceStatus,

            // Chart Data
            'revenueData' => json_encode($revenueData),
            'signupData' => json_encode($signupData),
            'topProducts' => $topProducts,

            // Currency
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }

    private function resellerDashboard($user)
    {
        $analytics = app(ResellerAnalyticsService::class);

        return view('dashboard.reseller', $analytics->dashboardMetrics($user));
    }

    private function customerDashboard($user)
    {
        $usageService = app(ServicePackageUsageService::class);
        $upgradeService = app(CustomerHostingUpgradeService::class);

        $packageUsageWarnings = collect($usageService->upgradeWarningsForUser($user))
            ->map(function (array $warning) use ($upgradeService, $user) {
                $warning['recommended_upgrade'] = $upgradeService->recommendedUpgrade(
                    $warning['service'],
                    $user,
                    $warning['primary_metric'] ?? null,
                );

                return $warning;
            });

        return view('dashboard.customer', [
            'activeServices' => $user->services()->where('status', 'active')->with('product')->get(),
            'suspendedServices' => $user->services()->where('status', 'suspended')->with('product')->get(),
            'provisioningServices' => $user->services()->whereIn('status', ['pending', 'provisioning'])->with('product')->get(),
            'upcomingDueInvoices' => $user->invoices()
                ->whereIn('status', ['unpaid', 'overdue'])
                ->orderBy('due_date')
                ->take(5)
                ->get(),
            'outstandingBalance' => $user->getOutstandingBalance(),
            'openTickets' => $user->tickets()->where('status', '!=', 'closed')->get(),
            'domains' => $user->domains()->where('status', 'active')->get(),
            'expiringDomains' => $user->domains()
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(30))
                ->orderBy('expires_at')
                ->get(),
            'creditBalance' => CreditService::getAvailableBalance($user),
            'packageUsageWarnings' => $packageUsageWarnings,
        ]);
    }
}
