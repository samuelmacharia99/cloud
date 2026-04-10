<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
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
        $validated = $request->validated();

        \Log::info('Manual payment creation started', [
            'user_id' => $validated['user_id'] ?? null,
            'invoice_id' => $validated['invoice_id'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
            'status' => $validated['status'] ?? null,
            'created_by_admin_id' => auth()->id(),
            'created_by_admin_name' => auth()->user()->name,
        ]);

        $payment = Payment::create($validated);

        \Log::info('Manual payment record created by admin', [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'invoice_id' => $payment->invoice_id,
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'status' => $payment->status,
            'created_by_admin_id' => auth()->id(),
            'created_by_admin_name' => auth()->user()->name,
        ]);

        // Auto-reconcile if invoice is linked and payment is completed
        if ($payment->invoice && $payment->status->isCompleted()) {
            \Log::debug('Auto-reconciling invoice after payment creation', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ]);
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
        $validated = $request->validated();

        \Log::info('Payment update started', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'user_id' => $payment->user_id,
            'payment_method' => $payment->payment_method,
            'old_status' => $oldStatus->value ?? $oldStatus,
            'new_status' => $validated['status'] ?? null,
            'amount' => $payment->amount,
            'updated_by_admin_id' => auth()->id(),
            'updated_by_admin_name' => auth()->user()->name,
        ]);

        $payment->update($validated);

        \Log::info('Payment updated successfully', [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'user_id' => $payment->user_id,
            'old_status' => $oldStatus->value ?? $oldStatus,
            'new_status' => $payment->status->value ?? $payment->status,
            'updated_by_admin_id' => auth()->id(),
            'updated_by_admin_name' => auth()->user()->name,
        ]);

        // If marking as reversed, handle reversal logic
        if ($oldStatus === PaymentStatus::Completed && $payment->status === PaymentStatus::Reversed) {
            \Log::info('Handling payment reversal', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'reversed_by_admin_id' => auth()->id(),
                'reversed_by_admin_name' => auth()->user()->name,
            ]);
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

        $wasUnpaid = $invoice->status !== 'paid';

        if ($amountPaid >= $invoice->total) {
            $invoice->update(['status' => 'paid']);

            // Auto-provision services if invoice just became paid
            if ($wasUnpaid) {
                $this->provisionServices($invoice);
            }
        } elseif ($amountPaid > 0) {
            $invoice->update(['status' => 'unpaid']);
        }
    }

    /**
     * Provision all pending services linked to an invoice.
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
            } catch (\Exception $e) {
                \Log::error("Auto-provisioning failed for service {$service->id}: {$e->getMessage()}");
            }
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

    /**
     * Approve a manual payment submission.
     * Marks payment as completed and triggers provisioning.
     */
    public function approveManual(Payment $payment)
    {
        // Guard: only allow approval of pending manual payments
        if ($payment->payment_method !== 'manual' || $payment->status !== 'pending') {
            \Log::warning('Manual payment approval rejected - invalid state', [
                'payment_id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'current_status' => $payment->status,
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
            ]);
            return back()->with('error', 'This payment cannot be approved.');
        }

        try {
            $oldStatus = $payment->status;
            $paidAt = now();

            \Log::info('Manual payment approval started', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'user_name' => $payment->user->name,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'old_status' => $oldStatus,
                'new_status' => 'completed',
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
                'approved_at' => $paidAt,
            ]);

            // Mark payment as completed
            $payment->update([
                'status'  => 'completed',
                'paid_at' => $paidAt,
            ]);

            \Log::info('Manual payment status updated to completed', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'paid_at' => $paidAt,
            ]);

            // Update invoice and trigger provisioning
            if ($payment->invoice) {
                \Log::debug('Updating invoice status for approved payment', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $payment->invoice_id,
                    'invoice_number' => $payment->invoice->invoice_number,
                ]);

                $this->updateInvoiceStatus($payment->invoice);

                \Log::info('Invoice status updated after payment approval', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $payment->invoice_id,
                    'invoice_status' => $payment->invoice->status,
                ]);
            }

            \Log::info('Manual payment approval completed successfully', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'approved_by_admin_id' => auth()->id(),
                'approved_by_admin_name' => auth()->user()->name,
                'approved_at' => $paidAt,
            ]);

            return back()->with('success', 'Manual payment approved successfully. Services have been provisioned.');
        } catch (\Exception $e) {
            \Log::error('Manual payment approval error', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to approve payment. ' . $e->getMessage());
        }
    }

    /**
     * Reject a manual payment submission.
     * Marks payment as failed with reason.
     */
    public function rejectManual(Request $request, Payment $payment)
    {
        // Guard: only allow rejection of pending manual payments
        if ($payment->payment_method !== 'manual' || $payment->status !== 'pending') {
            \Log::warning('Manual payment rejection rejected - invalid state', [
                'payment_id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'current_status' => $payment->status,
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
            ]);
            return back()->with('error', 'This payment cannot be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        try {
            $rejectionReason = $validated['rejection_reason'];
            $rejectionTime = now();

            \Log::info('Manual payment rejection started', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'user_name' => $payment->user->name,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'old_status' => $payment->status,
                'new_status' => 'failed',
                'rejection_reason' => $rejectionReason,
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
                'rejected_at' => $rejectionTime,
            ]);

            // Store rejection reason in notes
            $notes = json_decode($payment->notes, true) ?? [];
            $notes['rejection_reason'] = $rejectionReason;
            $notes['rejected_at'] = $rejectionTime->toIso8601String();
            $notes['rejected_by'] = auth()->user()->name;

            // Mark payment as failed
            $payment->update([
                'status' => 'failed',
                'notes'  => json_encode($notes),
            ]);

            \Log::info('Manual payment marked as failed', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'rejection_reason' => $rejectionReason,
            ]);

            // Send rejection notification to customer
            try {
                \Log::debug('Sending rejection notification to customer', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'user_email' => $payment->user->email,
                ]);

                \Notification::route('mail', $payment->user->email)
                    ->notify(new \App\Notifications\ManualPaymentRejected($payment));

                \Log::info('Rejection notification sent to customer', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'user_email' => $payment->user->email,
                ]);
            } catch (\Exception $notificationError) {
                \Log::warning('Failed to send rejection notification to customer', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'user_email' => $payment->user->email,
                    'error' => $notificationError->getMessage(),
                ]);
                // Don't fail the rejection just because notification failed
            }

            \Log::info('Manual payment rejection completed successfully', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'rejection_reason' => $rejectionReason,
                'rejected_by_admin_id' => auth()->id(),
                'rejected_by_admin_name' => auth()->user()->name,
                'rejected_at' => $rejectionTime,
            ]);

            return back()->with('success', 'Manual payment rejected. Customer has been notified.');
        } catch (\Exception $e) {
            \Log::error('Manual payment rejection error', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'user_id' => $payment->user_id,
                'admin_id' => auth()->id(),
                'admin_name' => auth()->user()->name,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to reject payment. ' . $e->getMessage());
        }
    }
}
