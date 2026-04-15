<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Service;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Product;
use App\Models\Currency;
use App\Models\Setting;

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
        // Key Metrics
        $totalCustomers = User::where('is_admin', false)->count();
        $activeServices = Service::where('status', 'active')->count();
        $totalServices = Service::count();
        $suspendedServices = Service::where('status', 'suspended')->count();
        $unpaidInvoiceTotal = Invoice::where('status', 'unpaid')->sum('total');
        $overdueInvoiceTotal = Invoice::where('status', 'overdue')->sum('total');
        $totalRevenue = Payment::where('status', 'completed')->sum('amount');
        $pendingPayments = Payment::where('status', 'pending')->sum('amount');
        $openTickets = Ticket::where('status', '!=', 'closed')->count();
        $urgentTickets = Ticket::where('status', '!=', 'closed')->where('priority', 'urgent')->count();

        // Recent Activity
        $recentCustomers = User::where('is_admin', false)->latest()->take(8)->get();
        $recentServices = Service::with('user', 'product')->latest()->take(8)->get();
        $recentInvoices = Invoice::with('user')->latest()->take(8)->get();
        $recentPayments = Payment::with('user', 'invoice')->latest()->take(8)->get();
        $openTickets_data = Ticket::with('user', 'assignee')->where('status', '!=', 'closed')->latest()->take(8)->get();

        // Status Breakdown
        $serviceStatus = [
            'active' => Service::where('status', 'active')->count(),
            'suspended' => Service::where('status', 'suspended')->count(),
            'terminated' => Service::where('status', 'terminated')->count(),
            'cancelled' => Service::where('status', 'cancelled')->count(),
        ];

        $invoiceStatus = [
            'unpaid' => Invoice::where('status', 'unpaid')->count(),
            'paid' => Invoice::where('status', 'paid')->count(),
            'overdue' => Invoice::where('status', 'overdue')->count(),
            'cancelled' => Invoice::where('status', 'cancelled')->count(),
        ];

        // Revenue Data (last 30 days)
        $revenueData = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $revenue = Payment::where('status', 'completed')
                ->whereDate('created_at', $date->toDateString())
                ->sum('amount');
            $revenueData[] = $revenue;
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
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $signups = User::where('is_admin', false)
                ->whereDate('created_at', $date->toDateString())
                ->count();
            $signupData[] = $signups;
        }

        return view('dashboard.admin', [
            // Metrics
            'totalCustomers' => $totalCustomers,
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
        // Load reseller package
        $user->load('resellerPackage');

        // Services managed by this reseller
        $managedServices = Service::where('reseller_id', $user->id)
            ->with('user', 'product')
            ->get();

        $customerIds = $managedServices->pluck('user_id')->unique();
        $managedCustomers = User::whereIn('id', $customerIds)->get();

        // Metrics
        $activeServices = $managedServices->where('status', 'active')->count();
        $suspendedServices = $managedServices->where('status', 'suspended')->count();
        $totalServices = $managedServices->count();

        // Revenue from managed services (invoices for customers managed by this reseller)
        $managedInvoices = Invoice::whereIn('user_id', $customerIds)->get();
        $paidInvoices = $managedInvoices->where('status', 'paid');
        $totalRevenue = $paidInvoices->sum('total');
        $unpaidInvoices = $managedInvoices->where('status', 'unpaid');
        $outstandingBalance = $unpaidInvoices->sum('total');

        // Commission calculation (example: 20% of paid invoices)
        $commissionRate = 0.20;
        $totalCommission = $totalRevenue * $commissionRate;

        $data = [
            'resellerPackage' => $user->resellerPackage,
            'activeServices' => $activeServices,
            'managedCustomers' => $managedCustomers,
            'managedServices' => $managedServices->take(8),
            'suspendedServices' => $suspendedServices,
            'totalServices' => $totalServices,
            'totalRevenue' => $totalRevenue,
            'outstandingBalance' => $outstandingBalance,
            'totalCommission' => $totalCommission,
            'recentInvoices' => $managedInvoices->sortByDesc('created_at')->take(5),
        ];

        return view('dashboard.reseller', $data);
    }

    private function customerDashboard($user)
    {
        $data = [
            'activeServices' => $user->services()->where('status', 'active')->get(),
            'upcomingDueInvoices' => $user->invoices()
                ->where('status', 'unpaid')
                ->orderBy('due_date')
                ->take(5)
                ->get(),
            'outstandingBalance' => $user->getOutstandingBalance(),
            'openTickets' => $user->tickets()->where('status', '!=', 'closed')->get(),
            'domains' => $user->domains()->where('status', 'active')->get(),
        ];

        return view('dashboard.customer', $data);
    }
}
