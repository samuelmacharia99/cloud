<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Customer\CustomerServiceCancellationService;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index()
    {
        $services = auth()->user()->services()
            ->with(['product', 'invoice'])
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->whereHas('product', function ($q) {
                $q->where('type', '!=', 'domain');
            })
            ->latest()
            ->get();

        return view('customer.services.index', compact('services'));
    }

    public function show(Service $service)
    {
        $this->authorize('view', $service);

        if ($invoice = $service->unpaidActivationInvoice()) {
            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('info', 'Complete payment to activate this service.');
        }

        // Redirect container services to their dedicated dashboard
        if ($service->product?->type === 'container_hosting') {
            return redirect()->route('customer.services.container.show', $service);
        }

        $service->load(['product', 'invoice', 'node']);

        return view('customer.services.show', compact('service'));
    }

    public function cancel(Request $request, Service $service, CustomerServiceCancellationService $cancellation)
    {
        $this->authorize('view', $service);

        $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        try {
            $result = $cancellation->cancel($service, auth()->user(), $request->reason);

            return redirect()->route('customer.services.index')
                ->with($result['deprovisioned'] ? 'success' : 'warning', $result['message']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function renew(Request $request, Service $service)
    {
        $this->authorize('view', $service);
        abort_if(
            ! in_array($service->status->value, ['active', 'suspended']),
            422,
            'Only active or suspended services can be renewed.'
        );

        $service->load('product');

        // If there is already an unpaid renewal invoice for this service, send the
        // customer straight to its payment page rather than creating a duplicate.
        $existingInvoice = InvoiceItem::where('service_id', $service->id)
            ->whereHas('invoice', function ($q) {
                $q->whereIn('status', ['draft', 'unpaid'])
                    ->where('created_at', '>=', now()->subDays(30));
            })
            ->with('invoice')
            ->latest('id')
            ->first()
            ?->invoice;

        if ($existingInvoice) {
            return redirect()->route('customer.payment.select-method', $existingInvoice)
                ->with('info', 'You already have an outstanding renewal invoice. Complete the payment below to extend your service.');
        }

        $price = $this->getPriceForCycle($service);
        $taxBreakdown = TaxService::calculateForUser($price, auth()->user());
        $tax = $taxBreakdown['tax'];
        $total = $taxBreakdown['total'];
        $dueDate = now()->addDays((int) Setting::getValue('invoice_due_days', 14))->toDateString();
        $prefix = Setting::getValue('invoice_prefix', 'INV');

        $invoice = DB::transaction(function () use ($service, $prefix, $price, $tax, $total, $dueDate, $taxBreakdown) {
            $year = now()->format('Y');
            $sequence = Invoice::whereYear('created_at', $year)->lockForUpdate()->count() + 1;
            $number = $prefix.'-'.$year.'-'.str_pad($sequence, 5, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'user_id' => $service->user_id,
                'invoice_number' => $number,
                'status' => 'unpaid',
                'due_date' => $dueDate,
                'subtotal' => $taxBreakdown['subtotal'],
                'tax' => $tax,
                'total' => $total,
                'notes' => 'Manual renewal — '.$service->product->name.' ('.ucfirst($service->billing_cycle).')',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $service->product_id,
                'description' => $service->product->name.' — '.ucfirst($service->billing_cycle).' renewal',
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
            ]);

            // Link the new invoice to the service so payment completion can resolve it.
            $service->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });

        return redirect()->route('customer.payment.select-method', $invoice)
            ->with('success', 'Renewal invoice created. Choose a payment method below to extend your service.');
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
