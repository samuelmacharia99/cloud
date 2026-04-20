<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;

class StripeService implements PaymentGatewayInterface
{
    protected ?string $apiKey;
    protected ?string $publishableKey;

    public function __construct()
    {
        $this->apiKey = Setting::getValue('stripe_secret_key', '');
        $this->publishableKey = Setting::getValue('stripe_publishable_key', '');

        if ($this->apiKey) {
            Stripe::setApiKey($this->apiKey);
        }
    }

    /**
     * Initiate Stripe payment by creating a checkout session
     */
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            if (!$this->isConfigured()) {
                throw new \Exception('Stripe is not configured');
            }

            $user = $invoice->user;
            $lineItems = [];

            // Get invoice items
            $invoiceItems = $invoice->items()->with('product')->get();
            foreach ($invoiceItems as $item) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => (int) round($item->amount * 100), // Convert to cents
                        'product_data' => [
                            'name' => $item->description,
                            'metadata' => [
                                'invoice_id' => $invoice->id,
                                'service_id' => $item->service_id,
                            ],
                        ],
                    ],
                    'quantity' => 1,
                ];
            }

            if (empty($lineItems)) {
                throw new \Exception('No items in invoice');
            }

            // Create checkout session
            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => route('payment.stripe.success', ['invoice_id' => $invoice->id]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('payment.stripe.cancel', ['invoice_id' => $invoice->id]),
                'customer_email' => $user->email,
                'client_reference_id' => $invoice->invoice_number,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'user_id' => $user->id,
                    'invoice_number' => $invoice->invoice_number,
                ],
            ]);

            // Store pending payment
            Payment::create([
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->total,
                'currency' => 'USD',
                'payment_method' => 'stripe',
                'transaction_reference' => $session->id,
                'status' => 'pending',
                'notes' => json_encode([
                    'session_id' => $session->id,
                    'payment_intent' => $session->payment_intent,
                ]),
            ]);

            return [
                'success' => true,
                'message' => 'Redirecting to Stripe checkout',
                'session_id' => $session->id,
                'checkout_url' => $session->url,
                'publishable_key' => $this->publishableKey,
            ];
        } catch (\Exception $e) {
            Log::error('Stripe initiate failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment initiation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify Stripe payment status
     */
    public function verify(string $transactionReference): array
    {
        try {
            if (!$this->isConfigured()) {
                throw new \Exception('Stripe is not configured');
            }

            // Get session by ID
            $session = Session::retrieve($transactionReference);

            $isSuccessful = $session->payment_status === 'paid';

            return [
                'success' => $isSuccessful,
                'status' => $session->payment_status,
                'amount' => $session->amount_total ? $session->amount_total / 100 : 0,
                'currency' => $session->currency,
                'payment_intent' => $session->payment_intent,
                'customer_email' => $session->customer_email,
                'session_id' => $session->id,
            ];
        } catch (\Exception $e) {
            Log::error('Stripe verify failed', [
                'transaction_reference' => $transactionReference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'unknown',
                'message' => 'Verification failed',
            ];
        }
    }

    /**
     * Handle Stripe webhook callback
     */
    public function handleCallback(array $data): array
    {
        return $this->handleWebhook($data);
    }

    /**
     * Handle Stripe webhook
     */
    public function handleWebhook(array $data): array
    {
        try {
            $type = $data['type'] ?? null;
            $eventData = $data['data']['object'] ?? [];

            if ($type === 'checkout.session.completed') {
                $sessionId = $eventData['id'] ?? null;
                $paymentStatus = $eventData['payment_status'] ?? null;
                $invoiceId = $eventData['metadata']['invoice_id'] ?? null;

                if (!$invoiceId || $paymentStatus !== 'paid') {
                    return ['success' => false, 'message' => 'Invalid payment data'];
                }

                // Find and update payment
                $payment = Payment::where('transaction_reference', $sessionId)->first();
                if (!$payment) {
                    // Create new payment record
                    $invoice = Invoice::find($invoiceId);
                    if (!$invoice) {
                        return ['success' => false, 'message' => 'Invoice not found'];
                    }

                    $payment = Payment::create([
                        'user_id' => $invoice->user_id,
                        'invoice_id' => $invoice->id,
                        'amount' => $eventData['amount_total'] / 100,
                        'currency' => strtoupper($eventData['currency']),
                        'payment_method' => 'stripe',
                        'transaction_reference' => $sessionId,
                        'status' => 'completed',
                        'paid_at' => now(),
                        'notes' => json_encode([
                            'payment_intent' => $eventData['payment_intent'],
                            'customer_email' => $eventData['customer_email'],
                        ]),
                    ]);
                } else {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                    ]);
                }

                // Update invoice
                $payment->invoice->update(['status' => 'paid']);

                return [
                    'success' => true,
                    'message' => 'Payment received',
                    'payment_id' => $payment->id,
                ];
            }

            return ['success' => true, 'message' => 'Event processed'];
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return [
                'success' => false,
                'message' => 'Webhook processing failed',
            ];
        }
    }

    public function getMethod(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return Setting::getValue('stripe_enabled') == '1'
            && !empty($this->apiKey)
            && !empty($this->publishableKey);
    }
}
