<?php

namespace App\Http\Controllers\Admin;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Setting;
use App\Models\Payment;
use App\Models\Service;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query();

        // Search
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('id', 'like', "%{$request->search}%")
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', "%{$request->search}%")
                            ->orWhere('email', 'like', "%{$request->search}%");
                    });
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $invoices = $query->with('user')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.invoices.index', compact('invoices'));
    }

    public function create()
    {
        $customers = User::where('is_admin', false)->orderBy('name')->get();
        return view('admin.invoices.create', compact('customers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|in:draft,unpaid,paid,overdue,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        // Auto-generate invoice number
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $year = now()->format('Y');
        $count = Invoice::whereYear('created_at', $year)->count() + 1;
        $validated['invoice_number'] = "{$prefix}-{$year}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
        $validated['tax'] ??= 0;

        Invoice::create($validated);

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('user', 'items.product', 'payments');
        return view('admin.invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice)
    {
        $customers = User::where('is_admin', false)->orderBy('name')->get();
        return view('admin.invoices.edit', compact('invoice', 'customers'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'status' => 'required|in:draft,unpaid,paid,overdue,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $validated['tax'] ??= 0;
        $invoice->update($validated);

        return redirect()->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice updated successfully.');
    }

    public function download(Invoice $invoice)
    {
        return \App\Services\InvoicePdfService::download($invoice);
    }

    public function preview(Invoice $invoice)
    {
        return \App\Services\InvoicePdfService::stream($invoice);
    }

    /**
     * Record a payment for an invoice.
     */
    public function addPayment(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount'                => 'required|numeric|min:0.01',
            'payment_method'        => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'transaction_reference' => 'nullable|string|max:255',
            'paid_at'               => 'nullable|date',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        try {
            \Log::info('Recording payment on invoice', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'user_id' => $invoice->user_id,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
            ]);

            // Create payment
            $payment = Payment::create([
                'user_id'                => $invoice->user_id,
                'invoice_id'             => $invoice->id,
                'amount'                 => $validated['amount'],
                'currency'               => 'KES',
                'payment_method'         => $validated['payment_method'],
                'transaction_reference'  => $validated['transaction_reference'],
                'status'                 => PaymentStatus::Completed->value,
                'paid_at'                => $validated['paid_at'] ?? now(),
                'notes'                  => $validated['notes'],
            ]);

            \Log::info('Payment record created', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
            ]);

            // Reconcile invoice status
            $amountPaid = $invoice->payments()
                ->where('status', PaymentStatus::Completed->value)
                ->sum('amount');

            $wasUnpaid = $invoice->status !== 'paid';

            if ($amountPaid >= $invoice->total) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_date' => now(),
                ]);

                \Log::info('Invoice marked as paid', [
                    'invoice_id' => $invoice->id,
                    'total_paid' => $amountPaid,
                    'invoice_total' => $invoice->total,
                ]);

                // Provision pending services if invoice just became paid
                if ($wasUnpaid) {
                    $this->provisionServices($invoice);
                }
            } else {
                $invoice->update(['status' => 'unpaid']);

                \Log::info('Invoice status set to unpaid', [
                    'invoice_id' => $invoice->id,
                    'total_paid' => $amountPaid,
                    'invoice_total' => $invoice->total,
                ]);
            }

            return redirect()->route('admin.invoices.show', $invoice)
                ->with('success', "Payment of \${$validated['amount']} recorded successfully.");

        } catch (\Exception $e) {
            \Log::error('Failed to record payment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to record payment. ' . $e->getMessage());
        }
    }

    /**
     * Provision services linked to the invoice.
     */
    private function provisionServices(Invoice $invoice): void
    {
        $provisioningService = new ProvisioningService();

        // Find all pending services for this invoice
        $services = Service::where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->get();

        foreach ($services as $service) {
            try {
                $provisioningService->provision($service);
                \Log::info("Service provisioned after invoice payment", [
                    'service_id' => $service->id,
                    'invoice_id' => $invoice->id,
                ]);
            } catch (\Exception $e) {
                \Log::error("Auto-provisioning failed for service {$service->id}: {$e->getMessage()}");
            }
        }
    }
}
