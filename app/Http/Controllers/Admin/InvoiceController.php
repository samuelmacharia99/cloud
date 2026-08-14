<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceNumberService;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\InvoicePdfService;
use App\Services\InvoiceTransferService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        if ($validated['status'] === InvoiceStatus::Paid->value) {
            return back()
                ->withInput()
                ->withErrors(['status' => 'Create the invoice as open, then record its payment so settlement runs correctly.']);
        }

        $validated['invoice_number'] = app(InvoiceNumberService::class)->nextYearly();
        $validated['tax'] ??= 0;

        $invoice = Invoice::create($validated);

        if ($invoice->status !== InvoiceStatus::Draft) {
            $this->notificationService->notifyInvoiceGenerated($invoice);
        }

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('user', 'payments')->loadItemsForDisplay();

        $transferCustomers = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereKeyNot($invoice->user_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.invoices.show', compact('invoice', 'transferCustomers'));
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

        if ($validated['status'] === InvoiceStatus::Paid->value && ! $invoice->isPaid()) {
            return back()
                ->withInput()
                ->withErrors(['status' => 'Use “Mark as paid” or record a payment so settlement and provisioning run correctly.']);
        }

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
        if ($invoice->payments()->exists()) {
            return back()->with('error', 'Invoices with payment history cannot be deleted. Cancel the invoice or reverse its payments instead.');
        }

        // Delete associated invoice items first
        $invoice->items()->delete();

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
        $validated = $request->validate([
            'paid_date' => 'nullable|date',
        ]);

        try {
            $payment = \DB::transaction(function () use ($validated, $invoice) {
                $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

                if ($lockedInvoice->isPaid() || $lockedInvoice->status === InvoiceStatus::Cancelled) {
                    throw new \RuntimeException('Only open invoices can be marked as paid.');
                }

                $amountRemaining = $lockedInvoice->getAmountRemaining();
                if ($amountRemaining <= 0) {
                    app(InvoiceSettlementService::class)->settleFromCredits($lockedInvoice);

                    return null;
                }

                $payment = Payment::create([
                    'user_id' => $lockedInvoice->user_id,
                    'invoice_id' => $lockedInvoice->id,
                    'amount' => $amountRemaining,
                    'currency' => $lockedInvoice->displayCurrency(),
                    'payment_method' => PaymentMethod::Manual,
                    'transaction_reference' => 'ADMIN-MARK-PAID-'.$lockedInvoice->id.'-'.Str::uuid(),
                    'status' => PaymentStatus::Completed,
                    'paid_at' => $validated['paid_date'] ?? now(),
                    'notes' => 'Marked as paid by admin',
                ]);

                \Log::info('Invoice marked as paid by admin', [
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'amount' => $amountRemaining,
                    'currency' => $lockedInvoice->displayCurrency(),
                    'admin_id' => auth()->id(),
                ]);

                return $payment;
            });

            $invoice->refresh();

            if ($payment) {
                app(InvoiceSettlementService::class)->settleFromPayment($payment);
            } else {
                app(InvoiceSettlementService::class)->settleFromCredits($invoice);
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
                'currency' => $invoice->displayCurrency(),
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

            if ($invoice->isFullyPaid()) {
                app(InvoiceSettlementService::class)->settleFromPayment($payment);
            } elseif ($remaining > 0 && in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
                $invoice->update(['status' => InvoiceStatus::Unpaid->value]);

                \Log::info('Invoice partially paid', [
                    'invoice_id' => $invoice->id,
                    'remaining' => $remaining,
                    'invoice_total' => $invoice->total,
                ]);
            }

            return redirect()->route('admin.invoices.show', $invoice)
                ->with('success', 'Payment of '.$invoice->displayCurrency().' '.number_format((float) $validated['amount'], 2).' recorded successfully.');

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

    public function transfer(Request $request, Invoice $invoice, InvoiceTransferService $transferService)
    {
        $validated = $request->validate([
            'target_user_id' => 'required|exists:users,id',
        ]);

        $targetCustomer = User::findOrFail($validated['target_user_id']);

        try {
            $result = $transferService->transferToCustomer($invoice, $targetCustomer);

            $flash = "Invoice transferred from {$result['from_customer']} to {$result['to_customer']}.";
            if ($result['services_transferred'] > 0) {
                $flash .= ' '.$result['services_transferred'].' linked service(s) moved with the invoice.';
            }

            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('success', $flash);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
