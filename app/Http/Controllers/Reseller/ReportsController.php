<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\ResellerAnalyticsService;
use App\Services\ResellerCustomerBillingService;
use App\Services\ResellerMarginService;
use App\Services\ResellerScopeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
        private ResellerAnalyticsService $analytics,
        private ResellerMarginService $margins,
    ) {}

    public function index(Request $request)
    {
        $reseller = auth()->user();
        $from = $request->get('from');
        $to = $request->get('to');

        return view('reseller.reports.index', [
            'marginSummary' => $this->analytics->marginSummary($reseller),
            'outstandingBalance' => app(ResellerCustomerBillingService::class)
                ->customerOutstandingTotal($reseller),
            'catalogMargins' => $this->margins->catalogMarginRows($reseller),
            'ledgerTotals' => $this->margins->ledgerTotals($reseller, $from, $to),
            'ledgerEntries' => $this->margins->ledgerQuery($reseller, $from, $to)->paginate(25)->withQueryString(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function exportCustomers(): StreamedResponse
    {
        $reseller = auth()->user();
        $customers = $this->scope->managedCustomersQuery($reseller)->orderBy('name')->get();

        return $this->csvResponse('whitelabel-customers.csv', function () use ($customers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'name', 'email', 'phone', 'company', 'created_at']);
            foreach ($customers as $customer) {
                fputcsv($out, [
                    $customer->id,
                    $customer->name,
                    $customer->email,
                    $customer->phone ?? '',
                    $customer->company ?? '',
                    $customer->created_at?->toDateTimeString() ?? '',
                ]);
            }
            fclose($out);
        });
    }

    public function exportInvoices(Request $request): StreamedResponse
    {
        $reseller = auth()->user();
        $query = $this->scopedInvoicesQuery($reseller, $request)->with('user')->latest();
        $invoices = $query->get();

        return $this->csvResponse('whitelabel-customer-invoices.csv', function () use ($invoices) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['invoice_number', 'customer', 'status', 'total', 'amount_remaining', 'due_date', 'created_at']);
            foreach ($invoices as $invoice) {
                fputcsv($out, [
                    $invoice->invoice_number,
                    $invoice->user?->name ?? '',
                    $invoice->status->value ?? $invoice->status,
                    $invoice->total,
                    $invoice->getAmountRemaining(),
                    $invoice->due_date?->toDateString() ?? '',
                    $invoice->created_at?->toDateTimeString() ?? '',
                ]);
            }
            fclose($out);
        });
    }

    public function exportRevenue(Request $request): StreamedResponse
    {
        $reseller = auth()->user();
        $customerIds = $this->scope->managedCustomerIds($reseller);

        $query = Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn ($q) => $q->whereIn('user_id', $customerIds ?: [0]))
            ->with('invoice.user')
            ->latest();

        if ($request->filled('from')) {
            $query->whereDate('paid_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('paid_at', '<=', $request->to);
        }

        $payments = $query->get();

        return $this->csvResponse('whitelabel-revenue.csv', function () use ($payments) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['paid_at', 'amount', 'method', 'invoice', 'customer', 'reference']);
            foreach ($payments as $payment) {
                fputcsv($out, [
                    $payment->paid_at?->toDateTimeString() ?? $payment->created_at?->toDateTimeString() ?? '',
                    $payment->amount,
                    $payment->payment_method->value ?? $payment->payment_method,
                    $payment->invoice?->invoice_number ?? '',
                    $payment->invoice?->user?->name ?? '',
                    $payment->transaction_reference ?? '',
                ]);
            }
            fclose($out);
        });
    }

    public function exportServices(): StreamedResponse
    {
        $reseller = auth()->user();
        $services = $this->scope->managedServicesQuery($reseller)
            ->with(['user', 'product'])
            ->latest()
            ->get();

        return $this->csvResponse('whitelabel-services.csv', function () use ($services) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['service', 'customer', 'product', 'status', 'billing_cycle', 'next_due', 'custom_price']);
            foreach ($services as $service) {
                fputcsv($out, [
                    $service->name,
                    $service->user?->name ?? '',
                    $service->product?->name ?? '',
                    $service->status->value ?? $service->status,
                    $service->billing_cycle ?? '',
                    $service->next_due_date?->toDateString() ?? '',
                    $service->custom_price ?? '',
                ]);
            }
            fclose($out);
        });
    }

    public function exportMargins(Request $request): StreamedResponse
    {
        $reseller = auth()->user();
        $entries = $this->margins->ledgerQuery($reseller, $request->get('from'), $request->get('to'))->get();

        return $this->csvResponse('whitelabel-margin-ledger.csv', function () use ($entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'customer', 'type', 'description', 'retail', 'wholesale', 'margin', 'invoice', 'payment_id']);
            foreach ($entries as $entry) {
                fputcsv($out, [
                    $entry->created_at?->toDateTimeString() ?? '',
                    $entry->customer?->name ?? '',
                    $entry->entry_type,
                    $entry->description,
                    $entry->retail_amount,
                    $entry->wholesale_amount,
                    $entry->margin_amount,
                    $entry->invoice?->invoice_number ?? '',
                    $entry->payment_id,
                ]);
            }
            fclose($out);
        });
    }

    private function scopedInvoicesQuery($reseller, Request $request)
    {
        $query = $this->scope->managedInvoicesQuery($reseller);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return $query;
    }

    private function csvResponse(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $writer();
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
