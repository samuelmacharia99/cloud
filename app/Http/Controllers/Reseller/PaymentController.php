<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\DomainPushService;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\ResellerInvoicePaymentService;
use App\Services\ResellerWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentGatewayFactory $gatewayFactory,
        protected ResellerInvoicePaymentService $invoicePaymentService,
        protected ResellerWalletService $walletService,
    ) {}

    public function selectMethod(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        if (! in_array($invoice->status->value, ['unpaid', 'overdue'])) {
            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid');
        }

        $gateways = $this->gatewayFactory->getAvailableGateways();
        $wallet = $this->walletService->getOrCreate(auth()->user());
        $amountDue = $this->invoicePaymentService->amountDue($invoice);

        if (request()->wantsJson()) {
            return response()->json([
                'gateways' => $gateways,
                'wallet_balance' => $wallet->balance,
                'amount_due' => $amountDue,
            ]);
        }

        return view('reseller.payment.select-method', compact('invoice', 'gateways', 'wallet', 'amountDue'));
    }

    public function initiate(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $request->validate([
            'method' => 'required|string|in:mpesa,stripe,paypal,manual,wallet',
            'phone' => 'required_if:method,mpesa|nullable|string',
            'apply_wallet' => 'nullable|boolean',
        ]);

        $method = $request->input('method');
        $reseller = auth()->user();

        try {
            if ($request->boolean('apply_wallet') || $method === 'wallet') {
                $this->invoicePaymentService->applyWallet($invoice, $reseller, true);
                $invoice->refresh();
            }

            if ($this->invoicePaymentService->amountDue($invoice) <= 0) {
                $this->processPaymentCompletion(null, $invoice->fresh());

                return redirect()->route('reseller.invoices.show', $invoice)
                    ->with('success', 'Invoice paid successfully using your wallet balance.');
            }

            if ($method === 'wallet') {
                return redirect()->back()
                    ->with('error', 'Wallet balance is not enough to cover this invoice. Apply wallet and choose another payment method for the remainder.');
            }

            if ($method === 'manual') {
                return redirect()->route('reseller.payment.manual-form', $invoice);
            }

            $gateway = $this->gatewayFactory->make($method);
            $amountDue = $this->invoicePaymentService->amountDue($invoice);

            $initiateData = $gateway->initiate($invoice, [
                'phone' => $request->input('phone'),
                'charge_amount' => $amountDue,
            ]);

            if (! ($initiateData['success'] ?? false)) {
                return redirect()->back()
                    ->with('error', $initiateData['message'] ?? 'Payment initiation failed');
            }

            if ($method === 'mpesa') {
                return redirect()->route('reseller.payment.verify-mpesa', [
                    'invoice' => $invoice,
                    'checkout_request_id' => $initiateData['checkout_request_id'] ?? null,
                ])->with('success', $initiateData['message'] ?? 'Please complete payment on your phone.');
            }

            if (isset($initiateData['checkout_url'])) {
                return redirect($initiateData['checkout_url']);
            }

            if (isset($initiateData['redirect_url'])) {
                return redirect($initiateData['redirect_url']);
            }

            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('success', 'Payment initiated. Please check your email for payment instructions.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Payment initiation failed: '.$e->getMessage());
        }
    }

    public function verifyMpesa(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $checkoutRequestId = (string) $request->query('checkout_request_id', '');
        if ($checkoutRequestId === '') {
            return redirect()->route('reseller.payment.select-method', $invoice)
                ->with('error', 'Missing checkout request reference. Please retry M-Pesa payment.');
        }

        try {
            return view('reseller.payment.verify-mpesa', [
                'invoice' => $invoice,
                'checkoutRequestId' => $checkoutRequestId,
            ]);
        } catch (\Exception $e) {
            \Log::error('verifyMpesa error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function mpesaStatus(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $checkoutRequestId = (string) request()->query('checkout_request_id', '');
        if ($checkoutRequestId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing checkout request ID'], 422);
        }

        $payment = Payment::where('invoice_id', $invoice->id)
            ->where('payment_method', 'mpesa')
            ->where('transaction_reference', $checkoutRequestId)
            ->first();

        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment not found']);
        }

        if ($payment->status->value === 'completed') {
            return response()->json(['status' => 'completed']);
        }

        if ($payment->status->value === 'failed') {
            $notes = json_decode($payment->notes, true) ?? [];

            return response()->json([
                'status' => 'failed',
                'message' => $notes['result_desc'] ?? 'Payment was cancelled or failed',
            ]);
        }

        try {
            // Reseller wholesale invoices always use platform M-Pesa (same as initiate()).
            $gateway = $this->gatewayFactory->make('mpesa');
            $result = $gateway->verify($payment->transaction_reference);

            if ($result['status'] === 'completed') {
                $this->processPaymentCompletion($payment, $invoice);

                return response()->json(['status' => 'completed']);
            }

            if ($result['status'] === 'failed') {
                $payment->update([
                    'status' => PaymentStatus::Failed->value,
                    'notes' => json_encode([
                        'result_desc' => $result['message'] ?? 'Payment failed',
                        'result_code' => $result['response_code'] ?? null,
                    ]),
                ]);

                return response()->json([
                    'status' => 'failed',
                    'message' => $result['message'] ?? 'Payment was cancelled or failed',
                ]);
            }

            return response()->json(['status' => $result['status']]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function success(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $payment = Payment::where('invoice_id', $invoice->id)
            ->where('status', PaymentStatus::Completed)
            ->latest()
            ->first();

        if ($payment) {
            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('success', 'Payment received successfully!');
        }

        return redirect()->route('reseller.invoices.show', $invoice);
    }

    public function stripeSuccess(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        try {
            $payment = Payment::where('invoice_id', $invoice->id)
                ->where('payment_method', 'stripe')
                ->where('status', '!=', PaymentStatus::Completed)
                ->latest()
                ->first();

            if ($payment) {
                $gateway = $this->gatewayFactory->make('stripe');
                $result = $gateway->verify($payment->transaction_reference);

                if ($result['status'] === 'completed') {
                    $this->processPaymentCompletion($payment, $invoice);
                }
            }

            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('success', 'Payment received successfully!');
        } catch (\Exception $e) {
            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('error', 'Payment verification failed: '.$e->getMessage());
        }
    }

    public function stripeCancel(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return redirect()->route('reseller.payment.select-method', $invoice)
            ->with('warning', 'Payment was cancelled');
    }

    public function paypalSuccess(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        try {
            $payment = Payment::where('invoice_id', $invoice->id)
                ->where('payment_method', 'paypal')
                ->where('status', '!=', PaymentStatus::Completed)
                ->latest()
                ->first();

            if ($payment) {
                $gateway = $this->gatewayFactory->make('paypal');
                $result = $gateway->verify($payment->transaction_reference);

                if ($result['status'] === 'completed') {
                    $this->processPaymentCompletion($payment, $invoice);
                }
            }

            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('success', 'Payment received successfully!');
        } catch (\Exception $e) {
            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('error', 'Payment verification failed: '.$e->getMessage());
        }
    }

    public function paypalCancel(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return redirect()->route('reseller.payment.select-method', $invoice)
            ->with('warning', 'PayPal payment was cancelled.');
    }

    public function manualForm(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return view('reseller.payment.manual-form', compact('invoice'));
    }

    public function manualSubmit(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $request->validate([
            'proof' => 'required|string|max:500',
        ]);

        try {
            $gateway = $this->gatewayFactory->make('manual');
            $initiateData = $gateway->initiate($invoice, [
                'proof' => $request->input('proof'),
                'charge_amount' => $this->invoicePaymentService->amountDue($invoice),
            ]);

            return redirect()->route('reseller.payment.manual-submitted', $initiateData['payment_id'] ?? '')
                ->with('success', 'Payment proof submitted. Admin will review and confirm.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to submit payment proof: '.$e->getMessage());
        }
    }

    public function manualSubmitted(Payment $payment)
    {
        abort_if($payment->user_id !== auth()->id(), 403);

        $payment->load('invoice');

        return view('reseller.payment.manual-submitted', ['payment' => $payment]);
    }

    private function processPaymentCompletion(?Payment $payment, Invoice $invoice): void
    {
        if ($payment) {
            $payment->update(['status' => PaymentStatus::Completed, 'paid_at' => now()]);
        }

        $invoice = $invoice->fresh();

        if (! $this->invoicePaymentService->completeInvoiceIfFullyPaid($invoice, $payment)) {
            return;
        }

        $this->runPostPaymentSideEffects($invoice->fresh(['items', 'user']), $payment);
    }

    /**
     * Non-critical hooks after the invoice is marked paid (must not roll back payment state).
     */
    private function runPostPaymentSideEffects(Invoice $invoice, ?Payment $payment): void
    {
        try {
            app(NotificationService::class)->notifyPaymentReceived($payment ?? $invoice);
        } catch (\Throwable $e) {
            Log::error('Payment notification failed after reseller invoice paid', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(DomainPushService::class)->handlePaidResellerInvoice($invoice);
        } catch (\Throwable $e) {
            Log::error('Domain push failed after reseller invoice paid', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->processDomainRenewals($invoice);

        foreach ($invoice->items as $item) {
            if (! $item->service_id) {
                continue;
            }

            $service = Service::find($item->service_id);
            if (! $service || $service->status->value !== 'pending') {
                continue;
            }

            $service->update(['status' => ServiceStatus::Provisioning]);

            try {
                Artisan::call('service:provision', ['service_id' => $service->id]);
            } catch (\Throwable $e) {
                Log::error('Reseller service provisioning failed after payment', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processDomainRenewals(Invoice $invoice): void
    {
        try {
            $renewalOrders = DomainRenewalOrder::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'invoiced')
                ->get();

            $renewalService = app(DomainRenewalService::class);

            foreach ($renewalOrders as $order) {
                $renewalService->pushRenewalToAdmin($order);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process domain renewals after reseller payment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
