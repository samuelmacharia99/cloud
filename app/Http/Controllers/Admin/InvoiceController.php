<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoicePdfService;
use App\Services\NotificationService;
use App\Services\Provisioning\InvoiceProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private NotificationService $notificationService) {}

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
        $validated['invoice_number'] = "{$prefix}-{$year}-".str_pad($count, 5, '0', STR_PAD_LEFT);
        $validated['tax'] ??= 0;

        $invoice = Invoice::create($validated);

        if ($invoice->status !== 'draft') {
            $this->notificationService->notifyInvoiceGenerated($invoice);
        }

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('user', 'payments')->loadItemsForDisplay();

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
        return InvoicePdfService::download($invoice);
    }

    public function preview(Invoice $invoice)
    {
        return InvoicePdfService::stream($invoice);
    }

    /**
     * Delete an invoice
     */
    public function destroy(Invoice $invoice)
    {
        // Delete associated invoice items first
        $invoice->items()->delete();

        // Delete associated payments
        $invoice->payments()->delete();

        // Delete the invoice
        $invoice->delete();

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Invoice deleted successfully.');
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(Request $request, Invoice $invoice)
    {
        try {
            \DB::transaction(function () use ($request, $invoice) {
                // Create a payment record for the full invoice amount
                Payment::create([
                    'user_id' => $invoice->user_id,
                    'invoice_id' => $invoice->id,
                    'amount' => $invoice->total,
                    'currency' => 'KES',
                    'payment_method' => 'manual',
                    'transaction_reference' => 'Manual payment - Admin marked as paid',
                    'status' => 'completed',
                    'paid_at' => $request->input('paid_date', now()),
                    'notes' => 'Marked as paid by admin',
                ]);

                // Update invoice status
                $invoice->update([
                    'status' => 'paid',
                    'paid_date' => $request->input('paid_date', now()),
                ]);

                \Log::info('Invoice marked as paid by admin', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => $invoice->total,
                    'admin_id' => auth()->id(),
                ]);
            });

            $invoice->refresh();

            try {
                $result = $this->provisionServices($invoice);
                \Log::info('Provisioning attempted after admin mark-as-paid', [
                    'invoice_id' => $invoice->id,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                \Log::error('Provisioning after admin mark-as-paid failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->back()
                ->with('success', 'Invoice marked as paid successfully.');
        } catch (\Exception $e) {
            \Log::error('Failed to mark invoice as paid', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to mark invoice as paid: '.$e->getMessage());
        }
    }

    /**
     * Record a payment for an invoice.
     */
    public function addPayment(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'transaction_reference' => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
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
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $validated['amount'],
                'currency' => 'KES',
                'payment_method' => $validated['payment_method'],
                'transaction_reference' => $validated['transaction_reference'] ?? null,
                'status' => PaymentStatus::Completed->value,
                'paid_at' => $validated['paid_at'] ?? now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            \Log::info('Payment record created', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
            ]);

            $invoice->refresh();
            $remaining = $invoice->getAmountRemaining();

            if ($remaining <= 0) {
                $invoice->update([
                    'status' => InvoiceStatus::Paid->value,
                    'paid_date' => $invoice->paid_date ?? now(),
                ]);

                \Log::info('Invoice marked as paid', [
                    'invoice_id' => $invoice->id,
                    'amount_paid' => $invoice->getAmountPaid(),
                    'wallet_applied' => $invoice->wallet_amount_applied,
                    'invoice_total' => $invoice->total,
                ]);
            } elseif ($remaining > 0 && in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
                $invoice->update(['status' => InvoiceStatus::Unpaid->value]);

                \Log::info('Invoice partially paid', [
                    'invoice_id' => $invoice->id,
                    'remaining' => $remaining,
                    'invoice_total' => $invoice->total,
                ]);
            }

            $invoice->refresh();

            if ($invoice->isFullyPaid()) {
                try {
                    $result = $this->provisionServices($invoice);
                    \Log::info('Provisioning attempted after admin payment', [
                        'invoice_id' => $invoice->id,
                        'result' => $result,
                    ]);
                } catch (\Throwable $e) {
                    \Log::error('Provisioning after admin payment failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $this->notificationService->notifyPaymentReceived($payment);
                } catch (\Throwable $e) {
                    \Log::error('Payment notification failed after admin payment', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return redirect()->route('admin.invoices.show', $invoice)
                ->with('success', 'Payment of KES '.number_format((float) $validated['amount'], 2).' recorded successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to record payment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('admin.invoices.show', $invoice)
                ->with('error', 'Failed to record payment. '.$e->getMessage());
        }
    }

    /**
     * @return array{provisioned: int, failed: array<int>, skipped: bool}
     */
    private function provisionServices(Invoice $invoice): array
    {
        return app(InvoiceProvisioningService::class)->provisionPendingServicesForInvoice($invoice);
    }
}
