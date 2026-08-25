<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Billing\InvoiceCurrencyService;
use Illuminate\Support\Facades\DB;

class AdminDashboardMetricsService
{
    public function __construct(
        private InvoiceCurrencyService $invoiceCurrency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
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

        $serviceStatusCounts = Service::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $invoiceStatusCounts = Invoice::query()
            ->platformBilling()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['unpaid', 'paid', 'overdue', 'cancelled'])
            ->groupBy('status')
            ->pluck('count', 'status');

        $arByStatus = $this->platformOutstandingByStatus();

        $totalRevenue = Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->sumAmountKes();

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $collectedToday = Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->whereEffectivePaidBetween($todayStart, $todayEnd)
            ->sumAmountKes();

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $collectedThisMonth = Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->whereEffectivePaidBetween($monthStart, $monthEnd)
            ->sumAmountKes();

        $openTickets = Ticket::visibleToAdmin()->where('status', '!=', 'closed')->count();
        $urgentTickets = Ticket::visibleToAdmin()->where('status', '!=', 'closed')->where('priority', 'urgent')->count();

        $recentCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->with('reseller:id,name')
            ->latest()
            ->take(8)
            ->get();

        $recentServices = Service::with('user', 'product')->latest()->take(8)->get();
        $recentInvoices = Invoice::query()
            ->platformBilling()
            ->with('user')
            ->latest()
            ->take(8)
            ->get();
        $recentPayments = Payment::query()
            ->platformRevenue()
            ->with('user', 'invoice')
            ->latest()
            ->take(8)
            ->get();
        $openTicketsData = Ticket::visibleToAdmin()
            ->with('user', 'assignee')
            ->where('status', '!=', 'closed')
            ->latest()
            ->take(8)
            ->get();

        $serviceStatus = [];
        foreach (ServiceStatus::cases() as $status) {
            $serviceStatus[$status->value] = (int) ($serviceStatusCounts[$status->value] ?? 0);
        }

        $invoiceStatus = [
            'unpaid' => (int) ($invoiceStatusCounts['unpaid'] ?? 0),
            'paid' => (int) ($invoiceStatusCounts['paid'] ?? 0),
            'overdue' => (int) ($invoiceStatusCounts['overdue'] ?? 0),
            'cancelled' => (int) ($invoiceStatusCounts['cancelled'] ?? 0),
        ];

        [$revenueData, $revenueLabels] = $this->dailyPlatformRevenueSeries(29);
        [$signupData, $signupLabels] = $this->dailyPlatformSignupSeries(6);

        $topProducts = Product::query()
            ->withCount([
                'services as services_count' => fn ($q) => $q->where('status', ServiceStatus::Active),
            ])
            ->orderByDesc('services_count')
            ->take(5)
            ->get();

        $currencyCode = Setting::getValue('currency', config('currency.base', 'KES'));
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return [
            'totalCustomers' => $platformCustomers + $resellerManagedCustomers,
            'platformCustomers' => $platformCustomers,
            'resellerManagedCustomers' => $resellerManagedCustomers,
            'totalResellers' => $totalResellers,
            'activeServices' => (int) ($serviceStatusCounts['active'] ?? 0),
            'totalServices' => (int) $serviceStatusCounts->sum(),
            'unpaidInvoiceTotal' => $arByStatus['unpaid'],
            'overdueInvoiceTotal' => $arByStatus['overdue'],
            'totalRevenue' => $totalRevenue,
            'collectedToday' => $collectedToday,
            'collectedThisMonth' => $collectedThisMonth,
            'openTickets' => $openTickets,
            'urgentTickets' => $urgentTickets,
            'recentCustomers' => $recentCustomers,
            'recentServices' => $recentServices,
            'recentInvoices' => $recentInvoices,
            'recentPayments' => $recentPayments,
            'openTickets_data' => $openTicketsData,
            'serviceStatus' => $serviceStatus,
            'invoiceStatus' => $invoiceStatus,
            'revenueData' => json_encode($revenueData),
            'revenueLabels' => json_encode($revenueLabels),
            'signupData' => json_encode($signupData),
            'signupLabels' => json_encode($signupLabels),
            'topProducts' => $topProducts,
            'currency' => $currency,
            'currencyCode' => $currencyCode,
            'collectedTodayDate' => now()->toDateString(),
            'collectedThisMonthStart' => $monthStart->toDateString(),
            'collectedThisMonthEnd' => $monthEnd->toDateString(),
            'collectedThisMonthLabel' => now()->format('F Y'),
        ];
    }

    /**
     * @return array{unpaid: float, overdue: float}
     */
    private function platformOutstandingByStatus(): array
    {
        $totals = ['unpaid' => 0.0, 'overdue' => 0.0];

        Invoice::query()
            ->platformBilling()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue])
            ->with([
                'payments' => fn ($q) => $q->where('status', PaymentStatus::Completed),
                'credits',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use (&$totals) {
                foreach ($invoices as $invoice) {
                    $status = $invoice->status instanceof InvoiceStatus
                        ? $invoice->status->value
                        : (string) $invoice->status;

                    if (! array_key_exists($status, $totals)) {
                        continue;
                    }

                    $totals[$status] += $this->invoiceCurrency->remainingBaseKes($invoice, false);
                }
            });

        return [
            'unpaid' => round($totals['unpaid'], 2),
            'overdue' => round($totals['overdue'], 2),
        ];
    }

    /**
     * @return array{0: list<float>, 1: list<string>}
     */
    private function dailyPlatformRevenueSeries(int $daysBackInclusive): array
    {
        $start = now()->subDays($daysBackInclusive)->startOfDay();
        $end = now()->endOfDay();

        $rows = Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->whereEffectivePaidBetween($start, $end)
            ->selectRaw('DATE(COALESCE(payments.paid_at, payments.created_at)) as day')
            ->selectRaw(Payment::amountKesSumSql().' as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $data = [];
        $labels = [];
        for ($i = $daysBackInclusive; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $key = $day->toDateString();
            $labels[] = $day->format('M j');
            $data[] = (float) ($rows[$key] ?? 0);
        }

        return [$data, $labels];
    }

    /**
     * @return array{0: list<int>, 1: list<string>}
     */
    private function dailyPlatformSignupSeries(int $daysBackInclusive): array
    {
        $start = now()->subDays($daysBackInclusive)->startOfDay();

        $rows = User::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNull('reseller_id')
            ->where('created_at', '>=', $start)
            ->groupBy('day')
            ->pluck('total', 'day');

        $data = [];
        $labels = [];
        for ($i = $daysBackInclusive; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $key = $day->toDateString();
            $labels[] = $day->format('D j');
            $data[] = (int) ($rows[$key] ?? 0);
        }

        return [$data, $labels];
    }
}
