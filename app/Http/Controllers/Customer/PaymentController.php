<?php

namespace App\Http\Controllers\Customer;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CreditService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    /**
     * Show payment history for customer
     */
    public function index(Request $request)
    {
        $payments = Payment::where('user_id', auth()->id())
            ->latest()
            ->paginate(10);

        return view('customer.payments.index', compact('payments'));
    }

    /**
     * Show specific payment details
     */
    public function show(Payment $payment)
    {
        abort_if($payment->user_id !== auth()->id(), 403);

        $payment->load('invoice');
        return view('customer.payments.show', compact('payment'));
    }

    /**
     * Show payment method selection for invoice
     */
    public function selectMethod(Invoice $invoice)
    {
        // Verify invoice belongs to authenticated user
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        // Don't allow payment for already paid invoices
        if ($invoice->status === 'paid') {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid');
        }

        $availableGateways = PaymentGatewayFactory::getAvailableGateways();

        if (empty($availableGateways)) {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'No payment methods available at the moment. Please contact support.');
        }

        return view('customer.payment.select-method', [
            'invoice' => $invoice,
            'availableGateways' => $availableGateways,
        ]);
    }

    /**
     * Initiate payment with selected gateway
     */
    public function initiate(Request $request, Invoice $invoice)
    {
        // Verify invoice belongs to authenticated user
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        // Validate request
        $request->validate([
            'payment_method' => 'required|string|in:mpesa,stripe,paypal',
            'phone' => 'required_if:payment_method,mpesa|nullable|string',
        ]);

        try {
            $gateway = PaymentGatewayFactory::make($request->payment_method);

            // Prepare customer data
            $customerData = [
                'phone' => $request->phone ?? auth()->user()->phone_number,
                'email' => auth()->user()->email,
            ];

            // Initiate payment
            $result = $gateway->initiate($invoice, $customerData);

            if (!$result['success']) {
                return back()->with('error', $result['message'] ?? 'Payment initiation failed');
            }

            // M-Pesa: Show prompt message and redirect
            if ($request->payment_method === 'mpesa') {
                return redirect()->route('customer.payment.verify-mpesa', [
                    'invoice_id' => $invoice->id,
                    'checkout_request_id' => $result['checkout_request_id'],
                ])->with('success', $result['message']);
            }

            // Stripe: Redirect to checkout
            if ($request->payment_method === 'stripe') {
                return redirect($result['checkout_url']);
            }

            // PayPal: Redirect to approval URL
            if ($request->payment_method === 'paypal') {
                return redirect($result['approval_url']);
            }

            return back()->with('error', 'Unknown payment method');
        } catch (\Exception $e) {
            Log::error('Payment initiation error', [
                'invoice_id' => $invoice->id,
                'method' => $request->payment_method,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Payment initiation failed. Please try again.');
        }
    }

    /**
     * M-Pesa: Verify payment (polling)
     */
    public function verifyMpesa(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        $checkoutRequestId = $request->get('checkout_request_id');
        if (!$checkoutRequestId) {
            return back()->with('error', 'Missing checkout request ID');
        }

        try {
            $gateway = PaymentGatewayFactory::make('mpesa');
            $result = $gateway->verify($checkoutRequestId);

            if ($result['success']) {
                // Payment completed
                $payment = Payment::where('transaction_reference', $checkoutRequestId)->first();
                if ($payment) {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);

                    // Process completion (handles overpayments and provisioning)
                    $this->processPaymentCompletion($payment, $invoice);
                }

                return redirect()->route('customer.payment.success', ['invoice_id' => $invoice->id])
                    ->with('success', 'Payment received successfully!');
            }

            // Still pending, show checking page
            return view('customer.payment.mpesa-verify', [
                'invoice' => $invoice,
                'checkout_request_id' => $checkoutRequestId,
                'message' => $result['message'] ?? 'Waiting for payment confirmation...',
            ]);
        } catch (\Exception $e) {
            Log::error('M-Pesa verification error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Verification failed. Please try again.');
        }
    }

    /**
     * Stripe: Success callback
     */
    public function stripeSuccess(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        $sessionId = $request->get('session_id');
        if (!$sessionId) {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Missing session ID');
        }

        try {
            $gateway = PaymentGatewayFactory::make('stripe');
            $result = $gateway->verify($sessionId);

            if ($result['success']) {
                // Update or create payment
                $payment = Payment::where('transaction_reference', $sessionId)->first();
                if ($payment) {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);
                } else {
                    $payment = Payment::create([
                        'user_id' => $invoice->user_id,
                        'invoice_id' => $invoice->id,
                        'amount' => $invoice->total,
                        'currency' => $result['currency'] ?? 'USD',
                        'payment_method' => 'stripe',
                        'transaction_reference' => $sessionId,
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);
                }

                // Process completion (handles overpayments and provisioning)
                $this->processPaymentCompletion($payment, $invoice);

                return redirect()->route('customer.payment.success', ['invoice_id' => $invoice->id])
                    ->with('success', 'Payment received successfully!');
            }

            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Payment verification failed');
        } catch (\Exception $e) {
            Log::error('Stripe success callback error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Payment verification failed');
        }
    }

    /**
     * Stripe: Cancel callback
     */
    public function stripeCancel(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        return redirect()->route('customer.payment.select-method', $invoice)
            ->with('info', 'Payment cancelled. You can try again later.');
    }

    /**
     * PayPal: Success callback
     */
    public function paypalSuccess(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        $orderId = $request->get('token');
        if (!$orderId) {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Missing PayPal order ID');
        }

        try {
            $gateway = PaymentGatewayFactory::make('paypal');
            $result = $gateway->verify($orderId);

            if ($result['success']) {
                // Update or create payment
                $payment = Payment::where('transaction_reference', $orderId)->first();
                if ($payment) {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);
                } else {
                    $payment = Payment::create([
                        'user_id' => $invoice->user_id,
                        'invoice_id' => $invoice->id,
                        'amount' => $result['amount'] ?? $invoice->total,
                        'currency' => $result['currency'] ?? 'USD',
                        'payment_method' => 'paypal',
                        'transaction_reference' => $orderId,
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);
                }

                // Process completion (handles overpayments and provisioning)
                $this->processPaymentCompletion($payment, $invoice);

                return redirect()->route('customer.payment.success', ['invoice_id' => $invoice->id])
                    ->with('success', 'Payment received successfully!');
            }

            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Payment verification failed');
        } catch (\Exception $e) {
            Log::error('PayPal success callback error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Payment verification failed');
        }
    }

    /**
     * PayPal: Cancel callback
     */
    public function paypalCancel(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        return redirect()->route('customer.payment.select-method', $invoice)
            ->with('info', 'Payment cancelled. You can try again later.');
    }

    /**
     * Show payment success page
     */
    public function success(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        return view('customer.payment.success', ['invoice' => $invoice]);
    }

    /**
     * Webhook: M-Pesa callback
     */
    public function mpesaCallback(Request $request)
    {
        try {
            $gateway = PaymentGatewayFactory::make('mpesa');
            $result = $gateway->handleCallback($request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('M-Pesa callback error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Webhook: Stripe webhook
     */
    public function stripeWebhook(Request $request)
    {
        try {
            $gateway = PaymentGatewayFactory::make('stripe');
            $result = $gateway->handleWebhook($request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Webhook: PayPal webhook
     */
    public function paypalWebhook(Request $request)
    {
        try {
            $gateway = PaymentGatewayFactory::make('paypal');
            $result = $gateway->handleWebhook($request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('PayPal webhook error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Process payment completion including credits and auto-provisioning
     */
    private function processPaymentCompletion(Payment $payment, Invoice $invoice): void
    {
        // Handle overpayments by creating credits
        if ($payment->isOverpayment()) {
            $payment->createCreditFromOverpayment();

            Log::info('Overpayment detected and credited', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'overpayment_amount' => $payment->getOverpaymentAmount(),
            ]);
        }

        // Mark invoice as paid
        $invoice->update(['status' => 'paid']);

        // Trigger service provisioning
        $this->provisionServices($invoice);
    }

    /**
     * Trigger service provisioning when payment is received
     *
     * This is called immediately after payment verification.
     * Services will go from "pending" → "provisioning" → "running"
     */
    private function provisionServices(Invoice $invoice): void
    {
        try {
            // Get all pending services from invoice items
            $services = $invoice->items()
                ->whereNotNull('service_id')
                ->with('service', 'product')
                ->get();

            $provisionedCount = 0;
            $failedServices = [];

            foreach ($services as $item) {
                if (!$item->service) {
                    continue;
                }

                try {
                    // Only provision services that are pending
                    if ($item->service->status !== 'pending') {
                        Log::info('Service already provisioned', [
                            'service_id' => $item->service->id,
                            'status' => $item->service->status,
                        ]);
                        continue;
                    }

                    // Mark as provisioning
                    $item->service->update(['status' => 'provisioning']);

                    // Call the provisioning command synchronously
                    $exitCode = \Artisan::call('service:provision', [
                        'service_id' => $item->service->id,
                    ]);

                    if ($exitCode === 0) {
                        $provisionedCount++;
                        Log::info('Service provisioned successfully', [
                            'service_id' => $item->service->id,
                            'invoice_id' => $invoice->id,
                            'user_id' => $invoice->user_id,
                        ]);
                    } else {
                        $failedServices[] = $item->service->id;
                        Log::warning('Service provisioning returned error code', [
                            'service_id' => $item->service->id,
                            'exit_code' => $exitCode,
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedServices[] = $item->service->id;
                    Log::error('Service provisioning exception', [
                        'service_id' => $item->service->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Services provisioning batch completed', [
                'invoice_id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'provisioned' => $provisionedCount,
                'failed' => count($failedServices),
                'failed_services' => $failedServices,
            ]);
        } catch (\Exception $e) {
            Log::error('Service provisioning trigger failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
