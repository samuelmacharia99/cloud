<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService implements PaymentGatewayInterface
{
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected bool $isProduction;
    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = Setting::getValue('paypal_client_id', '');
        $this->clientSecret = Setting::getValue('paypal_client_secret', '');
        $this->isProduction = Setting::getValue('paypal_environment', 'sandbox') === 'production';
        $this->baseUrl = $this->isProduction
            ? 'https://api.paypal.com'
            : 'https://api.sandbox.paypal.com';
    }

    /**
     * Initiate PayPal payment by creating an order
     */
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            if (!$this->isConfigured()) {
                throw new \Exception('PayPal is not configured');
            }

            // Get access token
            $token = $this->getAccessToken();
            if (!$token) {
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

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/v2/checkout/orders", $payload);

            if (!$response->successful()) {
                Log::error('PayPal order creation failed', [
                    'invoice_id' => $invoice->id,
                    'response' => $response->json(),
                ]);
                throw new \Exception('PayPal request failed');
            }

            $data = $response->json();
            $orderId = $data['id'] ?? null;

            if (!$orderId) {
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
                'message' => 'Payment initiation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify PayPal payment by capturing the order
     */
    public function verify(string $transactionReference): array
    {
        try {
            if (!$this->isConfigured()) {
                throw new \Exception('PayPal is not configured');
            }

            $token = $this->getAccessToken();
            if (!$token) {
                throw new \Exception('Failed to get access token');
            }

            // Capture the order
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/v2/checkout/orders/{$transactionReference}/capture", []);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status' => 'pending',
                    'message' => 'Capture failed',
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
                'status' => $status,
                'message' => 'Order not completed',
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

                if (!$orderId) {
                    return ['success' => false, 'message' => 'No order ID'];
                }

                // Find or create payment
                $payment = Payment::where('transaction_reference', $orderId)->first();

                if (!$payment) {
                    // Try to find by invoice
                    $invoiceId = $resource['custom_id'] ?? null;
                    if (!$invoiceId) {
                        return ['success' => false, 'message' => 'Invoice ID not found'];
                    }

                    $invoice = Invoice::find($invoiceId);
                    if (!$invoice) {
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
                }

                return [
                    'success' => $status === 'COMPLETED',
                    'message' => 'Webhook processed',
                    'payment_id' => $payment->id,
                ];
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
    private function getAccessToken(): ?string
    {
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
        return Setting::getValue('paypal_enabled') == '1'
            && !empty($this->clientId)
            && !empty($this->clientSecret);
    }
}
