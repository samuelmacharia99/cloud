<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments.
     */
    public function index(Request $request)
    {
        $query = Payment::with(['user', 'invoice'])
            ->latest('created_at');

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Filter by amount range
        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        $payments = $query->paginate(25);

        return view('admin.payments.index', [
            'payments' => $payments,
            'users' => User::where('is_admin', false)->orderBy('name')->get(),
            'paymentMethods' => PaymentMethod::options(),
            'statuses' => PaymentStatus::options(),
            'filters' => $request->only(['user_id', 'payment_method', 'status', 'from_date', 'to_date', 'min_amount', 'max_amount']),
        ]);
    }

    /**
     * Show the form for creating a new payment.
     */
    public function create()
    {
        return view('admin.payments.create', [
            'users' => User::where('is_admin', false)->orderBy('name')->get(),
            'invoices' => Invoice::orderBy('invoice_number')->get(),
            'paymentMethods' => PaymentMethod::options(),
            'statuses' => PaymentStatus::options(),
        ]);
    }

    /**
     * Store a newly created payment.
     */
    public function store(StorePaymentRequest $request)
    {
        $payment = Payment::create($request->validated());

        // Auto-reconcile if invoice is linked and payment is completed
        if ($payment->invoice && $payment->status->isCompleted()) {
            $this->updateInvoiceStatus($payment->invoice);
        }

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('success', 'Payment recorded successfully.');
    }

    /**
     * Display the specified payment.
     */
    public function show(Payment $payment)
    {
        $payment->load(['user', 'invoice']);

        return view('admin.payments.show', [
            'payment' => $payment,
        ]);
    }

    /**
     * Show the form for editing the payment.
     */
    public function edit(Payment $payment)
    {
        return view('admin.payments.edit', [
            'payment' => $payment,
            'statuses' => PaymentStatus::options(),
        ]);
    }

    /**
     * Update the specified payment.
     */
    public function update(UpdatePaymentRequest $request, Payment $payment)
    {
        $oldStatus = $payment->status;
        $payment->update($request->validated());

        // If marking as reversed, handle reversal logic
        if ($oldStatus === PaymentStatus::Completed && $payment->status === PaymentStatus::Reversed) {
            $this->handlePaymentReversal($payment);
        }

        return redirect()
            ->route('admin.payments.show', $payment)
            ->with('success', 'Payment updated successfully.');
    }

    /**
     * Destroy - disabled with proper message.
     */
    public function destroy(Payment $payment)
    {
        return redirect()
            ->back()
            ->with('error', 'Payments cannot be deleted. Use reversal instead.');
    }

    /**
     * Update invoice status based on payments.
     */
    private function updateInvoiceStatus(Invoice $invoice): void
    {
        $amountPaid = $invoice->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->sum('amount');

        if ($amountPaid >= $invoice->total) {
            $invoice->update(['status' => 'paid']);
        } elseif ($amountPaid > 0) {
            $invoice->update(['status' => 'unpaid']);
        }
    }

    /**
     * Handle payment reversal (e.g., reverse linked invoice reconciliation).
     */
    private function handlePaymentReversal(Payment $payment): void
    {
        if ($payment->invoice) {
            $this->updateInvoiceStatus($payment->invoice);
        }
    }
}
