<?php

namespace App\Http\Controllers\Customer;

use App\Models\Service;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\Ticket;
use App\Enums\ServiceStatus;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index()
    {
        $services = auth()->user()->services()->with('product')->latest()->get();
        return view('customer.services.index', compact('services'));
    }

    public function show(Service $service)
    {
        // Ensure customer can only view their own services
        if ($service->user_id !== auth()->id()) {
            abort(403);
        }

        $service->load(['product', 'invoice']);
        return view('customer.services.show', compact('service'));
    }

    public function cancel(Request $request, Service $service)
    {
        abort_if($service->user_id !== auth()->id(), 403);
        abort_if(in_array($service->status->value, ['terminated', 'cancelled']), 422);

        $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        DB::transaction(function () use ($service, $request) {
            $service->update(['status' => ServiceStatus::Cancelled]);

            Ticket::create([
                'user_id' => auth()->id(),
                'title' => 'Service Cancellation: ' . $service->name,
                'description' => $request->reason,
                'status' => 'open',
                'priority' => 'low',
            ]);
        });

        return redirect()->route('customer.services.index')
            ->with('success', 'Service cancelled successfully. A ticket has been created.');
    }

    public function renew(Request $request, Service $service)
    {
        abort_if($service->user_id !== auth()->id(), 403);
        abort_if($service->status->value === 'terminated', 422);

        // Check for existing unpaid invoice
        $existingInvoice = Invoice::where('id', $service->invoice_id)
            ->whereIn('status', ['draft', 'unpaid'])
            ->where('created_at', '>=', now()->subDays(7))
            ->exists();

        if ($existingInvoice) {
            return back()->with('error', 'You already have an outstanding invoice for this service. Please pay it before renewing.');
        }

        // Calculate price based on billing cycle
        $price = $this->getPriceForCycle($service);
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $taxEnabled = Setting::getValue('tax_enabled', 'false') === 'true';
        $tax = $taxEnabled ? round($price * $taxRate / 100, 2) : 0;
        $total = $price + $tax;

        // Create invoice
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $year = now()->format('Y');
        $count = Invoice::whereYear('created_at', $year)->count() + 1;
        $invoiceNumber = "{$prefix}-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
        $dueDate = now()->addDays((int) Setting::getValue('invoice_due_days', 14))->toDateString();

        DB::transaction(function () use ($service, $invoiceNumber, $price, $tax, $total, $dueDate) {
            $invoice = Invoice::create([
                'user_id' => $service->user_id,
                'invoice_number' => $invoiceNumber,
                'status' => 'unpaid',
                'due_date' => $dueDate,
                'subtotal' => $price,
                'tax' => $tax,
                'total' => $total,
                'notes' => 'Service renewal invoice for ' . $service->name,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'description' => $service->product->name . ' — ' . ucfirst($service->billing_cycle) . ' renewal',
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
            ]);

            $service->update(['invoice_id' => $invoice->id]);
        });

        return redirect()->route('customer.invoices.show', ['invoice' => $service->fresh()->invoice_id])
            ->with('success', 'Renewal invoice created. Please pay to extend your service.');
    }

    private function getPriceForCycle(Service $service): float
    {
        return match ($service->billing_cycle) {
            'monthly' => (float) $service->product->monthly_price,
            'quarterly' => (float) ($service->product->monthly_price * 3),
            'semi-annual' => (float) ($service->product->monthly_price * 6),
            'annual' => (float) $service->product->yearly_price ?: ($service->product->monthly_price * 12),
            default => (float) $service->product->price,
        };
    }
}
