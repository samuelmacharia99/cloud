<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\DomainRenewalService;
use App\Services\NotificationService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\PaymentGateway\PayPalService;
use App\Services\PaymentGateway\StripeService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;

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

        $availableGateways = PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice);

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
            'payment_method' => 'required|string|in:mpesa,stripe,paypal,manual',
            'phone' => 'required_if:payment_method,mpesa|nullable|string',
        ]);

        try {
            $gateway = PaymentGatewayFactory::makeForInvoice($request->payment_method, $invoice);

            // Prepare customer data
            $customerData = [
                'phone' => $request->phone ?? auth()->user()->phone_number,
                'email' => auth()->user()->email,
            ];

            // Initiate payment
            $result = $gateway->initiate($invoice, $customerData);

            if (! $result['success']) {
                return back()->with('error', $result['message'] ?? 'Payment initiation failed');
            }

            // Send order notification when payment method is initiated
            try {
                // Get the order for this invoice (orders are created alongside invoices)
                $order = Order::where('user_id', $invoice->user_id)
                    ->orderByDesc('created_at')
                    ->first();

                if ($order) {
                    $notificationService = app(NotificationService::class);
                    $notificationService->notifyNewOrder($order, $invoice, $request->payment_method);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send new order notifications', [
                    'invoice_id' => $invoice->id,
                    'payment_method' => $request->payment_method,
                    'error' => $e->getMessage(),
                ]);
            }

            // M-Pesa: Show prompt message and redirect
            if ($request->payment_method === 'mpesa') {
                return redirect()->route('customer.payment.verify-mpesa', [
                    'invoice' => $invoice,
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

            // Manual: Show form to collect payment details
            if ($request->payment_method === 'manual') {
                return redirect()->route('customer.payment.manual-form', ['invoice' => $invoice->id]);
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
        if (! $checkoutRequestId) {
            return back()->with('error', 'Missing checkout request ID');
        }

        try {
            $gateway = PaymentGatewayFactory::makeForInvoice('mpesa', $invoice);
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

                return redirect()->route('customer.payment.success', ['invoice' => $invoice])
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
        if (! $sessionId) {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Missing session ID');
        }

        try {
            // Retrieve the session from Stripe to verify metadata invoice_id matches this invoice
            try {
                Stripe::setApiKey(Setting::getValue('stripe_secret_key', ''));
                $session = Session::retrieve($sessionId);
                $metaInvoiceId = $session->metadata->invoice_id ?? null;

                if ((string) $metaInvoiceId !== (string) $invoice->id) {
                    Log::warning('Stripe session invoice_id mismatch', [
                        'session_id' => $sessionId,
                        'session_invoice_id' => $metaInvoiceId,
                        'route_invoice_id' => $invoice->id,
                        'user_id' => auth()->id(),
                    ]);
                    abort(403, 'Session does not match this invoice');
                }
            } catch (\Exception $e) {
                Log::error('Stripe session retrieval for verification failed', [
                    'invoice_id' => $invoice->id,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('customer.invoices.show', $invoice)
                    ->with('error', 'Payment verification failed');
            }

            $gateway = PaymentGatewayFactory::makeForInvoice('stripe', $invoice);
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

                return redirect()->route('customer.payment.success', ['invoice' => $invoice])
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
        if (! $orderId) {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'Missing PayPal order ID');
        }

        try {
            // Verify the PayPal order's custom_id matches this invoice before capturing
            /** @var PayPalService $gateway */
            $gateway = PaymentGatewayFactory::makeForInvoice('paypal', $invoice);
            $orderDetails = $gateway->getOrder($orderId);

            if ($orderDetails !== null) {
                $customId = $orderDetails['purchase_units'][0]['custom_id'] ?? null;
                if ((string) $customId !== (string) $invoice->id) {
                    Log::warning('PayPal order custom_id mismatch', [
                        'order_id' => $orderId,
                        'order_custom_id' => $customId,
                        'route_invoice_id' => $invoice->id,
                        'user_id' => auth()->id(),
                    ]);
                    abort(403, 'PayPal order does not match this invoice');
                }
            }

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

                return redirect()->route('customer.payment.success', ['invoice' => $invoice])
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

            // If payment was successful, trigger provisioning
            if ($result['success'] && isset($result['payment_id'])) {
                $payment = Payment::find($result['payment_id']);
                if ($payment && $payment->invoice) {
                    try {
                        $this->provisionServices($payment->invoice);
                    } catch (\Exception $e) {
                        Log::error('Auto-provisioning failed from webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return response('', 200);
        } catch (\Exception $e) {
            Log::error('M-Pesa callback error', ['error' => $e->getMessage()]);

            return response('', 200);
        }
    }

    /**
     * Webhook: Stripe webhook
     */
    public function stripeWebhook(Request $request)
    {
        $signature = $request->header('Stripe-Signature', '');

        if (empty($signature)) {
            Log::warning('Stripe webhook received without signature header');

            return response()->json(['error' => 'Missing signature'], 400);
        }

        try {
            /** @var StripeService $gateway */
            $gateway = PaymentGatewayFactory::makeForInvoice('stripe', $invoice);

            // Verify signature using raw body — must happen before any parsing
            try {
                $verifiedData = $gateway->verifyWebhookSignature($request->getContent(), $signature);
            } catch (SignatureVerificationException $e) {
                Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

                return response()->json(['error' => 'Invalid signature'], 400);
            } catch (\UnexpectedValueException $e) {
                Log::warning('Stripe webhook payload error', ['error' => $e->getMessage()]);

                return response()->json(['error' => 'Invalid payload'], 400);
            }

            $result = $gateway->handleWebhook($verifiedData);

            // If payment was successful, trigger provisioning
            if ($result['success'] && isset($result['payment_id'])) {
                $payment = Payment::find($result['payment_id']);
                if ($payment && $payment->invoice) {
                    try {
                        $this->provisionServices($payment->invoice);
                    } catch (\Exception $e) {
                        Log::error('Auto-provisioning failed from webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

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
            /** @var PayPalService $gateway */
            $gateway = PaymentGatewayFactory::makeForInvoice('paypal', $invoice);

            if (! $gateway->verifyWebhook($request)) {
                Log::warning('PayPal webhook signature verification failed');

                return response()->json(['error' => 'Invalid webhook signature'], 400);
            }

            $result = $gateway->handleWebhook($request->all());

            // If payment was successful, trigger provisioning
            if ($result['success'] && isset($result['payment_id'])) {
                $payment = Payment::find($result['payment_id']);
                if ($payment && $payment->invoice) {
                    try {
                        $this->provisionServices($payment->invoice);
                    } catch (\Exception $e) {
                        Log::error('Auto-provisioning failed from webhook', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

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

        // Send payment received notification
        try {
            $notificationService = app(NotificationService::class);
            $notificationService->notifyPaymentReceived($payment);
        } catch (\Exception $e) {
            Log::error('Failed to send payment received notification', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Trigger service provisioning (new services: pending → provisioning)
        $this->provisionServices($invoice);

        // Unsuspend any suspended services now that invoice is paid
        $this->unsuspendServices($invoice);

        // Advance next_due_date for services that were renewed (active/suspended at payment time)
        $this->advanceServiceBillingDates($invoice);

        // Process domain orders for reseller customers
        $this->processDomainOrdersForReseller($invoice);

        // Process domain renewal orders
        $this->processDomainRenewals($invoice);
    }

    private function processDomainOrdersForReseller(Invoice $invoice): void
    {
        if ($invoice->user->reseller_id === null) {
            return;
        }

        try {
            app('domain-push-service')->handlePaidDomainInvoice($invoice);
        } catch (\Exception $e) {
            Log::error('Failed to process domain orders after payment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processDomainRenewals(Invoice $invoice): void
    {
        try {
            $renewalOrders = DomainRenewalOrder::where('invoice_id', $invoice->id)
                ->where('status', 'invoiced')
                ->get();

            foreach ($renewalOrders as $order) {
                $renewalService = app(DomainRenewalService::class);
                $renewalService->pushRenewalToAdmin($order);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process domain renewals after payment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
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
                if (! $item->service) {
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

    /**
     * Unsuspend suspended services linked to a paid invoice.
     */
    private function unsuspendServices(Invoice $invoice): void
    {
        try {
            $provisioningService = new ProvisioningService;
            $notificationService = app(NotificationService::class);

            // Find all suspended services for this invoice
            $services = Service::where('invoice_id', $invoice->id)
                ->where('status', 'suspended')
                ->get();

            foreach ($services as $service) {
                try {
                    $provisioningService->unsuspend($service);
                    $notificationService->notifyServiceUnsuspended($service->fresh());

                    Log::info('Service unsuspended - invoice paid', [
                        'service_id' => $service->id,
                        'invoice_id' => $invoice->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to unsuspend service {$service->id}: {$e->getMessage()}");
                }
            }
        } catch (\Exception $e) {
            Log::error('Service unsuspension trigger failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Advance next_due_date for services that were manually renewed.
     *
     * Called after provisionServices() and unsuspendServices() so that suspended
     * services have already transitioned back to active before we query.
     * Only touches services that are active or suspended at query time — pending
     * services are new purchases handled by provisionServices(), not renewals.
     *
     * Billing dates advance from the payment date (today), not from the previous
     * due date, so a customer who renews on May 20 (monthly) is next due June 20.
     */
    private function advanceServiceBillingDates(Invoice $invoice): void
    {
        try {
            // Re-query services directly so we see statuses after unsuspension.
            $services = Service::where('invoice_id', $invoice->id)
                ->whereIn('status', ['active', 'suspended'])
                ->get();

            foreach ($services as $service) {
                $newDueDate = match ($service->billing_cycle) {
                    'monthly' => now()->addMonth(),
                    'quarterly' => now()->addMonths(3),
                    'semi-annual' => now()->addMonths(6),
                    'annual' => now()->addYear(),
                    default => now()->addMonth(),
                };

                $service->update(['next_due_date' => $newDueDate]);

                Log::info('Service billing date advanced after payment', [
                    'service_id' => $service->id,
                    'invoice_id' => $invoice->id,
                    'billing_cycle' => $service->billing_cycle,
                    'new_due_date' => $newDueDate->toDateString(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to advance service billing dates', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manual Payment: Show form to collect payment details
     */
    public function manualPaymentForm(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        if ($invoice->status === 'paid') {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid');
        }

        return view('customer.payment.manual-form', ['invoice' => $invoice]);
    }

    /**
     * Manual Payment: Submit payment details for admin review
     */
    public function submitManualPayment(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        $validated = $request->validate([
            'payment_reference' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:100',
            'account_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $gateway = PaymentGatewayFactory::makeForInvoice('manual', $invoice);
            $result = $gateway->initiate($invoice, array_merge($validated, [
                'currency' => 'KES',
            ]));

            if ($result['success']) {
                return redirect()->route('customer.payment.manual-submitted', [
                    'payment' => $result['payment_id'],
                ])->with('success', $result['message']);
            }

            return back()->with('error', $result['message'] ?? 'Failed to submit payment details');
        } catch (\Exception $e) {
            Log::error('Manual payment submission error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to submit payment details. Please try again.');
        }
    }

    /**
     * Manual Payment: Show submission confirmation page
     */
    public function manualPaymentSubmitted(Payment $payment)
    {
        abort_if($payment->user_id !== auth()->id(), 403, 'Unauthorized');

        $payment->load('invoice');

        return view('customer.payment.manual-submitted', ['payment' => $payment]);
    }

    /**
     * M-Pesa: Poll payment status (AJAX) and update if completed
     */
    public function mpesaStatus(Request $request, Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403, 'Unauthorized');

        $request->validate([
            'checkout_request_id' => 'required|string',
        ]);

        try {
            $checkoutRequestId = $request->input('checkout_request_id');

            $gateway = PaymentGatewayFactory::makeForInvoice('mpesa', $invoice);
            $result = $gateway->verify($checkoutRequestId);

            // If payment is confirmed, update the database and process completion
            if ($result['success'] && $result['status'] === 'completed') {
                $payment = Payment::where('transaction_reference', $checkoutRequestId)->first();

                if ($payment && $payment->status !== 'completed') {
                    // Update payment status
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);

                    Log::info('M-Pesa payment confirmed via polling', [
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'checkout_request_id' => $checkoutRequestId,
                    ]);

                    // Process completion (handles overpayments, invoice update, and provisioning)
                    try {
                        $this->processPaymentCompletion($payment, $invoice);
                    } catch (\Exception $e) {
                        Log::error('Payment completion processing failed', [
                            'payment_id' => $payment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return response()->json([
                'status' => $result['status'] ?? 'pending',
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Awaiting payment confirmation',
            ]);
        } catch (\Exception $e) {
            Log::error('M-Pesa status poll error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'pending',
                'success' => false,
                'message' => 'Unable to check payment status',
            ]);
        }
    }
}
