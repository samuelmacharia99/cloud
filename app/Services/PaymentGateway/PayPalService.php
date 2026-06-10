<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService implements PaymentGatewayInterface
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected bool $isProduction;

    protected string $baseUrl;

    public function __construct(
        protected ?PayPalConnectService $connectService = null,
    ) {
        $this->connectService ??= app(PayPalConnectService::class);
        $this->clientId = Setting::getValue('paypal_client_id', '');
        $this->clientSecret = Setting::getValue('paypal_client_secret', '');
        $this->isProduction = Setting::getValue('paypal_environment', 'sandbox') === 'production';
        $this->baseUrl = $this->isProduction
            ? 'https://api.paypal.com'
            : 'https://api.sandbox.paypal.com';
    }

    protected function usesPartnerConnection(): bool
    {
        return $this->connectService->isConnected()
            && PayPalConnectService::isPartnerConfigured();
    }

    /**
     * Initiate PayPal payment by creating an order
     */
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            if (! $this->isConfigured()) {
                throw new \Exception('PayPal is not configured');
            }

            // Get access token
            $token = $this->getAccessToken();
            if (! $token) {
                throw new \Exception('Failed to get PayPal access token');
            }

            // Get invoice items
            $invoiceItems = $invoice->items()->with('product')->get();
            $items = [];

            foreach ($invoiceItems as $item) {
                $items[] = [
                    'name' => $item->description,
                    'unit_amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($item->amount, 2, '.', ''),
                    ],
                    'quantity' => '1',
                ];
            }

            if (empty($items)) {
                throw new \Exception('No items in invoice');
            }

            // Create PayPal order
            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $invoice->invoice_number,
                        'description' => "Invoice {$invoice->invoice_number}",
                        'custom_id' => $invoice->id,
                        'soft_descriptor' => 'Talksasa Cloud',
                        'items' => $items,
                        'amount' => [
                            'currency_code' => 'USD',
                            'value' => number_format($invoice->total, 2, '.', ''),
                            'breakdown' => [
                                'item_total' => [
                                    'currency_code' => 'USD',
                                    'value' => number_format($invoice->subtotal, 2, '.', ''),
                                ],
                                'tax_total' => [
                                    'currency_code' => 'USD',
                                    'value' => number_format($invoice->tax, 2, '.', ''),
                                ],
                            ],
                        ],
                    ],
                ],
                'payer' => [
                    'name' => [
                        'given_name' => $invoice->user->name,
                    ],
                    'email_address' => $invoice->user->email,
                ],
                'application_context' => [
                    'brand_name' => 'Talksasa Cloud',
                    'return_url' => route('payment.paypal.success', ['invoice_id' => $invoice->id]),
                    'cancel_url' => route('payment.paypal.cancel', ['invoice_id' => $invoice->id]),
                    'user_action' => 'PAY_NOW',
                    'locale' => 'en-US',
                ],
            ];

            if ($this->usesPartnerConnection()) {
                $payload['purchase_units'][0]['payee'] = [
                    'merchant_id' => Setting::getValue('paypal_merchant_id'),
                ];
            }

            $response = Http::withHeaders($this->apiHeaders($token))
                ->post("{$this->baseUrl}/v2/checkout/orders", $payload);

            if (! $response->successful()) {
                Log::error('PayPal order creation failed', [
                    'invoice_id' => $invoice->id,
                    'response' => $response->json(),
                ]);
                throw new \Exception('PayPal request failed');
            }

            $data = $response->json();
            $orderId = $data['id'] ?? null;

            if (! $orderId) {
                throw new \Exception('No order ID from PayPal');
            }

            // Find approval URL
            $approvalUrl = null;
            foreach ($data['links'] ?? [] as $link) {
                if ($link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            // Store pending payment
            Payment::create([
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->total,
                'currency' => 'USD',
                'payment_method' => 'paypal',
                'transaction_reference' => $orderId,
                'status' => 'pending',
                'notes' => json_encode([
                    'order_id' => $orderId,
                    'status' => $data['status'] ?? null,
                ]),
            ]);

            return [
                'success' => true,
                'message' => 'Redirecting to PayPal',
                'order_id' => $orderId,
                'approval_url' => $approvalUrl,
            ];
        } catch (\Exception $e) {
            Log::error('PayPal initiate failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment initiation failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Verify PayPal payment by capturing the order
     */
    public function verify(string $transactionReference): array
    {
        try {
            if (! $this->isConfigured()) {
                throw new \Exception('PayPal is not configured');
            }

            $token = $this->getAccessToken();
            if (! $token) {
                throw new \Exception('Failed to get access token');
            }

            // Capture the order
            $response = Http::withHeaders($this->apiHeaders($token))
                ->post("{$this->baseUrl}/v2/checkout/orders/{$transactionReference}/capture", []);

            if (! $response->successful()) {
                $errorBody = $response->json();
                $message = $errorBody['details'][0]['description']
                    ?? $errorBody['message']
                    ?? 'PayPal capture failed';

                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => $message,
                ];
            }

            $data = $response->json();
            $status = $data['status'] ?? null;

            // Check if capture was successful
            $isSuccessful = $status === 'COMPLETED';

            if ($isSuccessful) {
                $purchaseUnit = $data['purchase_units'][0] ?? [];
                $capture = $purchaseUnit['payments']['captures'][0] ?? [];

                return [
                    'success' => true,
                    'status' => 'completed',
                    'amount' => $capture['amount']['value'] ?? 0,
                    'currency' => $capture['amount']['currency_code'] ?? 'USD',
                    'transaction_id' => $capture['id'] ?? null,
                    'payer_email' => $data['payer']['email_address'] ?? null,
                ];
            }

            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'PayPal order was not completed.',
            ];
        } catch (\Exception $e) {
            Log::error('PayPal verify failed', [
                'transaction_reference' => $transactionReference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'pending',
                'message' => 'Verification failed',
            ];
        }
    }

    /**
     * Retrieve a PayPal order by ID (without capturing).
     * Returns the decoded order array, or null on failure.
     */
    public function getOrder(string $orderId): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (! $token) {
                return null;
            }

            $response = Http::withHeaders($this->apiHeaders($token, json: false))
                ->get("{$this->baseUrl}/v2/checkout/orders/{$orderId}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('PayPal getOrder failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('PayPal getOrder exception', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Verify a PayPal webhook event using the PayPal verify-webhook-signature API.
     * Returns true only when PayPal responds with verificationStatus == SUCCESS.
     */
    public function verifyWebhook(Request $request): bool
    {
        try {
            $webhookId = Setting::getValue('paypal_webhook_id', '');

            if (empty($webhookId)) {
                Log::warning('PayPal webhook_id setting is not configured — skipping signature verification');

                return false;
            }

            $token = $this->getAccessToken();
            if (! $token) {
                Log::error('PayPal webhook verification: failed to obtain access token');

                return false;
            }

            $payload = [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO', ''),
                'cert_url' => $request->header('PAYPAL-CERT-URL', ''),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID', ''),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG', ''),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME', ''),
                'webhook_id' => $webhookId,
                'webhook_event' => $request->json()->all(),
            ];

            $response = Http::withHeaders($this->apiHeaders($token))
                ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", $payload);

            if (! $response->successful()) {
                Log::warning('PayPal webhook verification API call failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $verificationStatus = $response->json('verification_status');

            if ($verificationStatus !== 'SUCCESS') {
                Log::warning('PayPal webhook verification returned non-SUCCESS', [
                    'verification_status' => $verificationStatus,
                ]);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('PayPal webhook verification exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Handle PayPal webhook callback
     */
    public function handleCallback(array $data): array
    {
        return $this->handleWebhook($data);
    }

    /**
     * Handle PayPal webhook
     */
    public function handleWebhook(array $data): array
    {
        try {
            $eventType = $data['event_type'] ?? null;
            $resource = $data['resource'] ?? [];

            if ($eventType === 'CHECKOUT.ORDER.COMPLETED' || $eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                $orderId = $resource['id'] ?? null;
                $status = $resource['status'] ?? null;

                if (! $orderId) {
                    return ['success' => false, 'message' => 'No order ID'];
                }

                // Find or create payment
                $payment = Payment::where('transaction_reference', $orderId)->first();

                if (! $payment) {
                    // Try to find by invoice
                    $invoiceId = $resource['custom_id'] ?? null;
                    if (! $invoiceId) {
                        return ['success' => false, 'message' => 'Invoice ID not found'];
                    }

                    $invoice = Invoice::find($invoiceId);
                    if (! $invoice) {
                        return ['success' => false, 'message' => 'Invoice not found'];
                    }

                    $payment = Payment::create([
                        'user_id' => $invoice->user_id,
                        'invoice_id' => $invoice->id,
                        'amount' => $resource['amount']['value'] ?? $invoice->total,
                        'currency' => $resource['amount']['currency_code'] ?? 'USD',
                        'payment_method' => 'paypal',
                        'transaction_reference' => $orderId,
                        'status' => $status === 'COMPLETED' ? 'completed' : 'pending',
                        'paid_at' => $status === 'COMPLETED' ? now() : null,
                        'notes' => json_encode(['webhook_event' => $eventType]),
                    ]);
                } else {
                    if ($status === 'COMPLETED') {
                        $payment->update([
                            'status' => 'completed',
                            'paid_at' => now(),
                        ]);
                    }
                }

                // Update invoice if payment completed
                if ($status === 'COMPLETED') {
                    $payment->invoice->update(['status' => 'paid']);

                    if ($payment->invoice->order) {
                        $payment->invoice->order->update([
                            'status' => 'paid',
                            'payment_status' => 'paid',
                        ]);
                    }
                }

                return [
                    'success' => $status === 'COMPLETED',
                    'message' => 'Webhook processed',
                    'payment_id' => $payment->id,
                ];
            }

            if ($eventType === 'MERCHANT.ONBOARDING.COMPLETED') {
                $this->connectService->refreshConnectionStatus();

                return ['success' => true, 'message' => 'Merchant onboarding status refreshed'];
            }

            if (in_array($eventType, ['CHECKOUT.ORDER.VOIDED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'], true)) {
                $orderId = $resource['id'] ?? null;

                if ($eventType === 'PAYMENT.CAPTURE.DENIED' || $eventType === 'PAYMENT.CAPTURE.DECLINED') {
                    $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? $orderId;
                }

                if ($orderId) {
                    $reason = match ($eventType) {
                        'CHECKOUT.ORDER.VOIDED' => 'PayPal order was voided before capture.',
                        'PAYMENT.CAPTURE.DENIED' => 'PayPal payment capture was denied.',
                        default => 'PayPal payment capture was declined.',
                    };

                    app(OnlinePaymentFailureService::class)->recordAndNotifyByReference($orderId, $reason);
                }

                return ['success' => true, 'message' => 'Failure event processed'];
            }

            return ['success' => true, 'message' => 'Event logged'];
        } catch (\Exception $e) {
            Log::error('PayPal webhook processing failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return [
                'success' => false,
                'message' => 'Webhook processing failed',
            ];
        }
    }

    /**
     * Get PayPal access token
     */
    /**
     * @return array<string, string>
     */
    private function apiHeaders(string $token, bool $json = true): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->usesPartnerConnection()) {
            $partnerClientId = (string) PayPalConnectService::partnerClientId();
            $bnCode = PayPalConnectService::partnerBnCode();
            if (filled($bnCode)) {
                $headers['PayPal-Partner-Attribution-Id'] = (string) $bnCode;
            }
            $headers['PayPal-Auth-Assertion'] = $this->connectService->buildAuthAssertion(
                $partnerClientId,
                (string) Setting::getValue('paypal_merchant_id')
            );
        }

        return $headers;
    }

    private function getAccessToken(): ?string
    {
        if ($this->usesPartnerConnection()) {
            return $this->connectService->getPartnerAccessToken(
                $this->isProduction ? 'production' : 'sandbox'
            );
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->successful()) {
                return $response->json()['access_token'] ?? null;
            }

            Log::error('Failed to get PayPal access token', [
                'response' => $response->json(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('PayPal token generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function getMethod(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        if (Setting::getValue('paypal_enabled') != '1') {
            return false;
        }

        if ($this->usesPartnerConnection()) {
            return Setting::getValue('paypal_onboarding_ready') === '1';
        }

        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }
}
