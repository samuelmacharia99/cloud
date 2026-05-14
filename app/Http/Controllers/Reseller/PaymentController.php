<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentGatewayFactory $gatewayFactory,
    ) {}

    public function selectMethod(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        if (!in_array($invoice->status->value, ['unpaid', 'overdue'])) {
            return redirect()->route('reseller.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid');
        }

        $gateways = $this->gatewayFactory->getAvailableGateways();

        if (request()->wantsJson()) {
            return response()->json(['gateways' => $gateways]);
        }

        return view('reseller.payment.select-method', compact('invoice', 'gateways'));
    }

    public function initiate(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $request->validate([
            'method' => 'required|string|in:mpesa,stripe,paypal,manual',
            'phone' => 'required_if:method,mpesa|nullable|string',
        ]);

        $method = $request->input('method');

        try {
            $gateway = $this->gatewayFactory->make($method);

            $initiateData = $gateway->initiate($invoice, [
                'phone' => $request->input('phone'),
            ]);

            if (!($initiateData['success'] ?? false)) {
                return redirect()->back()
                    ->with('error', $initiateData['message'] ?? 'Payment initiation failed');
            }

            if ($method === 'mpesa') {
                return redirect()->route('reseller.payment.verify-mpesa', $invoice);
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
                ->with('error', 'Payment initiation failed: ' . $e->getMessage());
        }
    }

    public function verifyMpesa(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return view('reseller.payment.verify-mpesa', compact('invoice'));
    }

    public function mpesaStatus(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $payment = Payment::where('invoice_id', $invoice->id)
            ->where('payment_method', 'mpesa')
            ->where('status', '!=', PaymentStatus::Completed)
            ->latest()
            ->first();

        if (!$payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment not found']);
        }

        try {
            $gateway = $this->gatewayFactory->make('mpesa');
            $result = $gateway->verify($payment->transaction_reference);

            if ($result['status'] === 'completed') {
                $this->processPaymentCompletion($payment, $invoice);

                return response()->json(['status' => 'completed']);
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
                ->with('error', 'Payment verification failed: ' . $e->getMessage());
        }
    }

    public function stripeCancel(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        return redirect()->route('reseller.payment.select-method', $invoice)
            ->with('warning', 'Payment was cancelled');
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
            ]);

            return redirect()->route('reseller.payment.manual-submitted', $initiateData['payment_id'] ?? '')
                ->with('success', 'Payment proof submitted. Admin will review and confirm.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to submit payment proof: ' . $e->getMessage());
        }
    }

    private function processPaymentCompletion(Payment $payment, Invoice $invoice)
    {
        try {
            \DB::beginTransaction();

            $invoice->update(['status' => 'paid']);
            $payment->update(['status' => PaymentStatus::Completed]);

            // Notify about payment
            \App\Services\NotificationService::notifyPaymentReceived($invoice);

            // Push all queued domain orders linked to this invoice
            foreach ($invoice->items as $item) {
                if ($item->product_type === 'Domain' && isset($item->custom_options['domain_order_id'])) {
                    $domainOrderId = $item->custom_options['domain_order_id'];
                    $order = ResellerDomainOrder::find($domainOrderId);

                    if ($order && $order->status === 'queued') {
                        $domainPushService = app(\App\Services\DomainPushService::class);
                        $domainPushService->pushOrderWithDirectPayment($order);
                    }
                }
            }

            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Payment completion failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
