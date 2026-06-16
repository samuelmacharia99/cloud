<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Database\Eloquent\Builder;

class TelegramWeeklySalesReportCommand extends BaseCronCommand
{
    protected $signature = 'cron:telegram-weekly-sales-report';

    protected $description = 'Send weekly Telegram sales report with 7-day renewal/payment forecast';

    protected function handleCron(): string
    {
        $start = now()->subDays(7);
        $end = now();

        $completedPayments = Payment::query()
            ->where('status', 'completed')
            ->whereBetween('paid_at', [$start, $end]);

        $paymentsCount = (clone $completedPayments)->count();
        $paymentsTotalKes = (float) (clone $completedPayments)
            ->selectRaw('COALESCE(SUM(CASE WHEN currency = ? OR currency IS NULL THEN amount ELSE 0 END), 0) as total', [config('currency.base', 'KES')])
            ->value('total');

        $ordersCount = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $newCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $dueStart = now()->startOfDay();
        $dueEnd = now()->addDays(7)->endOfDay();

        $serviceDueInvoices = Invoice::query()
            ->whereBetween('due_date', [$dueStart, $dueEnd])
            ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
            ->whereHas('items', fn (Builder $q) => $q->whereNotNull('service_id'))
            ->get();

        $domainDueInvoices = Invoice::query()
            ->whereBetween('due_date', [$dueStart, $dueEnd])
            ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
            ->whereHas('items', fn (Builder $q) => $q->whereNotNull('domain_id'))
            ->get();

        $serviceDueKes = $serviceDueInvoices->sum(fn (Invoice $invoice) => (float) ($invoice->total_base_kes ?: $invoice->total));
        $domainDueKes = $domainDueInvoices->sum(fn (Invoice $invoice) => (float) ($invoice->total_base_kes ?: $invoice->total));

        $forecastGross = $serviceDueKes + $domainDueKes;
        $forecastLow = $forecastGross * 0.50;
        $forecastBase = $forecastGross * 0.70;
        $forecastHigh = $forecastGross * 0.85;

        app(TelegramMonitorBridge::class)->systemAlert('Weekly sales report + next 7-day forecast', [
            'Period' => $start->format('Y-m-d').' to '.$end->format('Y-m-d'),
            'Payments completed (7d)' => (string) $paymentsCount,
            'Sales collected (7d, KES)' => number_format($paymentsTotalKes, 2),
            'Orders created (7d)' => (string) $ordersCount,
            'New customers (7d)' => (string) $newCustomers,
            'Service payments due (next 7d, KES)' => number_format($serviceDueKes, 2),
            'Domain renewals due (next 7d, KES)' => number_format($domainDueKes, 2),
            'Forecast gross due (next 7d, KES)' => number_format($forecastGross, 2),
            'Forecast range (next 7d, KES)' => number_format($forecastLow, 2).' - '.number_format($forecastHigh, 2),
            'Forecast base 70% (next 7d, KES)' => number_format($forecastBase, 2),
        ]);

        return 'Weekly sales report sent successfully.';
    }
}
