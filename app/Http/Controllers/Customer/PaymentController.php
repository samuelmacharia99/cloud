<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\CreditService;
use App\Services\CustomerCreditTopupService;
use App\Services\NotificationService;
use App\Services\PaymentGateway\BankTransferPaymentService;
use App\Services\PaymentGateway\OnlinePaymentFailureService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\PaymentGateway\PayPalService;
use App\Services\PaymentGateway\StripeService;
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
        $this->authorize('viewAny', Payment::class);

        $query = Payment::where('user_id', auth()->id())->with('invoice')->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $payments = $query->paginate(10)->withQueryString();

        return view('customer.payments.index', compact('payments'));
    }

    /**
     * Show specific payment details
     */
    public function show(Payment $payment)
    {
        $this->authorize('view', $payment);

        $payment->load('invoice');

        return view('customer.payments.show', compact('payment'));
    }

    /**
     * Show payment method selection for invoice
     */
    public function selectMethod(Invoice $invoice, InvoiceSettlementService $settlement)
    {
        $this->authorize('pay', $invoice);

        $invoice->refresh();
        $settlement->applyAvailableCredits($invoice);
        $invoice->refresh();

        if ($invoice->status->value === 'paid' || $invoice->isFullyPaid()) {
            $settlement->settleFromCredits($invoice);

            return redirect()->route('customer.payment.success', $invoice)
                ->with('success', 'Invoice paid using your account credit.');
        }

        $availableGateways = PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice);
        $creditBalance = CreditService::getAvailableBalance(auth()->user());
        $bankDetails = BankTransferPaymentService::bankDetails();

        if (empty($availableGateways) && $creditBalance <= 0) {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', 'No payment methods available at the moment. Please contact support.');
        }

        return view('customer.payment.select-method', [
            'invoice' => $invoice->fresh(),
            'availableGateways' => $availableGateways,
            'creditBalance' => $creditBalance,
            'appliedCredits' => $invoice->getAppliedCredits(),
            'amountRemaining' => $invoice->getAmountRemaining(),
            'bankDetails' => $bankDetails,
        ]);
    }

    public function applyCredits(Invoice $invoice, InvoiceSettlementService $settlement)
    {
        $this->authorize('pay', $invoice);

        $settlement->applyAvailableCredits($invoice);
        $invoice->refresh();

        if ($settlement->settleFromCredits($invoice)) {
            return redirect()->route('customer.payment.success', $invoice)
                ->with('success', 'Invoice paid using your account credit.');
        }

        return redirect()->route('customer.payment.select-method', $invoice)
            ->with('info', 'Credits applied. Pay the remaining balance below.');
    }

    /**
     * Initiate payment with selected gateway
     */
    public function initiate(Request $request, Invoice $invoice)
    {
        // Verify invoice belongs to authenticated user
        $this->authorize('pay', $invoice);

        $request->validate([
            'payment_method' => 'required|string|in:mpesa,stripe,paypal,manual,bank_transfer',
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
                    $notificationService->notifyNewOrder($order, $invoice, $request->payment_method, notifyAdmin: false);
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

            if ($request->payment_method === 'manual') {
                return redirect()->route('customer.payment.manual-form', ['invoice' => $invoice->id]);
            }

            if ($request->payment_method === 'bank_transfer') {
                return redirect()->route('customer.payment.bank-transfer-form', ['invoice' => $invoice->id]);
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

            if (($result['status'] ?? null) === 'failed') {
                $payment = Payment::where('transaction_reference', $checkoutRequestId)->first();
                if ($payment && $payment->status->value !== 'failed') {
                    $payment->update([
                        'status' => 'failed',
                        'notes' => json_encode([
                            'result_desc' => $result['message'] ?? 'Payment failed',
                            'result_code' => $result['response_code'] ?? null,
                        ]),
                    ]);
                    app(NotificationService::class)->notifyPaymentFailed(
                        $payment->fresh(['invoice.user']),
                        $result['message'] ?? 'Payment was cancelled or failed.',
                    );
                }

                return redirect()->route('customer.payment.select-method', $invoice)
                    ->with('error', $result['message'] ?? 'Payment was cancelled or failed.');
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
                if ($this->handleTopupPaymentCompletion($payment)) {
                    return redirect()->route('customer.credits.index')
                        ->with('success', 'Credits added to your account successfully!');
                }

                $this->processPaymentCompletion($payment, $invoice);

                return redirect()->route('customer.payment.success', ['invoice' => $invoice])
                    ->with('success', 'Payment received successfully!');
            }

            if (($result['status'] ?? null) === 'failed') {
                app(OnlinePaymentFailureService::class)->recordAndNotify(
                    $invoice,
                    'stripe',
                    $result['message'] ?? 'Card payment was not completed.',
                    $sessionId,
                );
            }

            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', $result['message'] ?? 'Payment verification failed');
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

        if ($this->isCreditTopupInvoice($invoice)) {
            return redirect()->route('customer.credits.index')
                ->with('info', 'Credit purchase cancelled. You can try again anytime.');
        }

        app(OnlinePaymentFailureService::class)->recordAndNotify(
            $invoice,
            'stripe',
            'Stripe checkout was cancelled.',
            $request->get('session_id'),
        );

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
                if ($this->handleTopupPaymentCompletion($payment)) {
                    return redirect()->route('customer.credits.index')
                        ->with('success', 'Credits added to your account successfully!');
                }

                $this->processPaymentCompletion($payment, $invoice);

                return redirect()->route('customer.payment.success', ['invoice' => $invoice])
                    ->with('success', 'Payment received successfully!');
            }

            if (($result['status'] ?? null) === 'failed') {
                app(OnlinePaymentFailureService::class)->recordAndNotify(
                    $invoice,
                    'paypal',
                    $result['message'] ?? 'PayPal payment was not completed.',
                    $orderId,
                );
            }

            return redirect()->route('customer.invoices.show', $invoice)
                ->with('error', $result['message'] ?? 'Payment verification failed');
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

        if ($this->isCreditTopupInvoice($invoice)) {
            return redirect()->route('customer.credits.index')
                ->with('info', 'Credit purchase cancelled. You can try again anytime.');
        }

        app(OnlinePaymentFailureService::class)->recordAndNotify(
            $invoice,
            'paypal',
            'PayPal checkout was cancelled.',
            $request->get('token'),
        );

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
                if (isset($result['wallet_topup']) || isset($result['credit_topup'])) {
                    return response('', 200);
                }

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
            $gateway = PaymentGatewayFactory::make('stripe');

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
                if ($payment && $payment->payment_purpose === 'credit_topup') {
                    return response()->json($result);
                }

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
            $gateway = PaymentGatewayFactory::make('paypal');

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
        if ($this->handleTopupPaymentCompletion($payment)) {
            return;
        }

        app(InvoiceSettlementService::class)->settleFromPayment($payment);
    }

    private function handleTopupPaymentCompletion(Payment $payment): bool
    {
        if ($payment->payment_purpose === 'credit_topup') {
            app(CustomerCreditTopupService::class)->processTopupPayment($payment);

            return true;
        }

        return false;
    }

    private function isCreditTopupInvoice(Invoice $invoice): bool
    {
        return str_starts_with((string) $invoice->invoice_number, 'CREDIT-');
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
        $this->authorize('view', $payment);

        $payment->load('invoice');

        return view('customer.payment.manual-submitted', ['payment' => $payment]);
    }

    public function bankTransferForm(Invoice $invoice)
    {
        $this->authorize('pay', $invoice);

        if ($invoice->status->value === 'paid') {
            return redirect()->route('customer.invoices.show', $invoice)
                ->with('info', 'This invoice has already been paid');
        }

        return view('customer.payment.bank-transfer-form', [
            'invoice' => $invoice,
            'bankDetails' => BankTransferPaymentService::bankDetails(),
            'amountRemaining' => $invoice->getAmountRemaining(),
        ]);
    }

    public function submitBankTransfer(Request $request, Invoice $invoice)
    {
        $this->authorize('pay', $invoice);

        $validated = $request->validate([
            'payment_reference' => 'required|string|max:100',
            'transfer_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $gateway = PaymentGatewayFactory::makeForInvoice('bank_transfer', $invoice);
            $result = $gateway->initiate($invoice, array_merge($validated, ['currency' => 'KES']));

            if ($result['success']) {
                return redirect()->route('customer.payment.bank-transfer-submitted', [
                    'payment' => $result['payment_id'],
                ])->with('success', $result['message']);
            }

            return back()->with('error', $result['message'] ?? 'Failed to submit bank transfer');
        } catch (\Exception $e) {
            Log::error('Bank transfer submission error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to submit bank transfer. Please try again.');
        }
    }

    public function bankTransferSubmitted(Payment $payment)
    {
        $this->authorize('view', $payment);

        $payment->load('invoice');

        return view('customer.payment.bank-transfer-submitted', [
            'payment' => $payment,
            'bankDetails' => BankTransferPaymentService::bankDetails(),
        ]);
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

        $checkoutRequestId = $request->input('checkout_request_id');

        $payment = Payment::query()
            ->where('transaction_reference', $checkoutRequestId)
            ->where('invoice_id', $invoice->id)
            ->where('user_id', auth()->id())
            ->first();

        if (! $payment) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Payment not found for this invoice',
            ], 404);
        }

        if ($payment->status->value === 'completed') {
            return response()->json(['status' => 'completed', 'success' => true]);
        }

        if ($payment->status->value === 'failed') {
            $notes = json_decode($payment->notes, true) ?? [];

            return response()->json([
                'status' => 'failed',
                'success' => false,
                'message' => $notes['result_desc'] ?? 'Payment was cancelled or failed',
            ]);
        }

        try {
            $gateway = PaymentGatewayFactory::makeForInvoice('mpesa', $invoice);
            $result = $gateway->verify($checkoutRequestId);

            if ($result['success'] && $result['status'] === 'completed') {
                $payment->refresh();

                if ($payment->status->value !== 'completed') {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);

                    Log::info('M-Pesa payment confirmed via polling', [
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'checkout_request_id' => $checkoutRequestId,
                    ]);
                }

                try {
                    $this->processPaymentCompletion($payment, $invoice);
                } catch (\Exception $e) {
                    Log::error('Payment completion processing failed', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (($result['status'] ?? null) === 'failed') {
                if ($payment->status->value !== 'failed') {
                    $payment->update([
                        'status' => 'failed',
                        'notes' => json_encode([
                            'result_desc' => $result['message'] ?? 'Payment failed',
                            'result_code' => $result['response_code'] ?? null,
                        ]),
                    ]);
                    app(NotificationService::class)->notifyPaymentFailed(
                        $payment->fresh(['invoice.user']),
                        $result['message'] ?? 'Payment was cancelled or failed.',
                    );
                }

                return response()->json([
                    'status' => 'failed',
                    'success' => false,
                    'message' => $result['message'] ?? 'Payment was cancelled or failed',
                ]);
            }

            return response()->json([
                'status' => $result['status'] ?? 'pending',
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Awaiting payment confirmation',
            ]);
        } catch (\Exception $e) {
            Log::error('M-Pesa status poll error', [
                'invoice_id' => $invoice->id,
                'checkout_request_id' => $checkoutRequestId,
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
