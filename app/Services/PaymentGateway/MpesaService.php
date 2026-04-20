<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Enums\PaymentStatus;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MpesaService implements PaymentGatewayInterface
{
    protected ?string $consumerKey;
    protected ?string $consumerSecret;
    protected ?string $businessShortCode;
    protected ?string $passkey;
    protected bool $isProduction;
    protected string $baseUrl;
    protected string $siteUrl;

    public function __construct()
    {
        $this->consumerKey = Setting::getValue('mpesa_consumer_key', '');
        $this->consumerSecret = Setting::getValue('mpesa_consumer_secret', '');
        $this->businessShortCode = (string) Setting::getValue('mpesa_shortcode', '');
        $this->passkey = Setting::getValue('mpesa_passkey', '');
        $this->isProduction = Setting::getValue('mpesa_environment', 'sandbox') === 'production';
        $this->siteUrl = Setting::getValue('site_url', 'http://localhost:8000');
        $this->baseUrl = $this->isProduction
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Initiate M-Pesa STK push (Lipa na M-Pesa Online)
     */
    public function initiate(Invoice $invoice, array $customerData = []): array
    {
        try {
            $phone = $this->sanitizePhone($customerData['phone'] ?? '');
            $token = $this->getAccessToken();

            if (!$token) {
                throw new \Exception('Failed to get M-Pesa access token');
            }

            $timestamp = now()->format('YmdHis');
            $password = base64_encode($this->businessShortCode . $this->passkey . $timestamp);

            $payload = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) ceil($invoice->total),
                'PartyA' => $phone,
                'PartyB' => $this->businessShortCode,
                'PhoneNumber' => $phone,
                'CallBackURL' => $this->buildCallbackUrl(),
                'AccountReference' => $invoice->invoice_number,
                'TransactionDesc' => "Invoice {$invoice->invoice_number}",
            ];

            Log::info('M-Pesa STK Push Request', [
                'invoice_id' => $invoice->id,
                'phone' => $phone,
                'callback_url' => $this->buildCallbackUrl(),
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", $payload);

            if (!$response->successful()) {
                Log::error('M-Pesa STK Push failed', [
                    'invoice_id' => $invoice->id,
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ]);
                throw new \Exception('M-Pesa request failed: ' . ($response->json()['errorMessage'] ?? 'Unknown error'));
            }

            $data = $response->json();

            Payment::create([
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->total,
                'currency' => 'KES',
                'payment_method' => 'mpesa',
                'transaction_reference' => $data['CheckoutRequestID'] ?? null,
                'status' => 'pending',
                'notes' => json_encode([
                    'response_code' => $data['ResponseCode'] ?? null,
                    'checkout_request_id' => $data['CheckoutRequestID'] ?? null,
                ]),
            ]);

            return [
                'success' => true,
                'message' => 'Please check your phone for M-Pesa prompt',
                'checkout_request_id' => $data['CheckoutRequestID'] ?? null,
                'response_code' => $data['ResponseCode'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa initiate failed', [
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
     * Verify M-Pesa payment via STK push query
     */
    public function verify(string $transactionReference): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                throw new \Exception('Failed to get access token');
            }

            $timestamp = now()->format('YmdHis');
            $password = base64_encode($this->businessShortCode . $this->passkey . $timestamp);

            $payload = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $transactionReference,
            ];

            Log::info('M-Pesa STK Query Request', [
                'checkout_request_id' => $transactionReference,
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/stkpushquery/v1/query", $payload);

            if (!$response->successful()) {
                Log::error('M-Pesa query request failed', [
                    'checkout_request_id' => $transactionReference,
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ]);
                return [
                    'success' => false,
                    'status' => 'pending',
                    'message' => 'Query failed',
                ];
            }

            $data = $response->json();
            Log::info('M-Pesa query response', [
                'checkout_request_id' => $transactionReference,
                'result_code' => $data['ResultCode'] ?? null,
            ]);

            $resultCode = $data['ResultCode'] ?? null;
            $isSuccessful = $resultCode === '0';

            return [
                'success' => $isSuccessful,
                'status' => $isSuccessful ? 'completed' : 'pending',
                'response_code' => $resultCode,
                'message' => $data['ResultDesc'] ?? 'No message',
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa verify failed', [
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
     * Handle M-Pesa callback webhook
     */
    public function handleCallback(array $data): array
    {
        try {
            $body = $data['Body'] ?? [];
            $stkCallback = $body['stkCallback'] ?? [];

            $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
            $resultCode = $stkCallback['ResultCode'] ?? null;

            Log::info('M-Pesa Callback Received', [
                'checkout_request_id' => $checkoutRequestId,
                'result_code' => $resultCode,
            ]);

            $payment = Payment::where('transaction_reference', $checkoutRequestId)->first();
            if (!$payment) {
                Log::warning('M-Pesa payment not found', ['checkout_request_id' => $checkoutRequestId]);
                return ['success' => false, 'message' => 'Payment not found'];
            }

            if ($resultCode == 0) {
                $callbackMetadata = $stkCallback['CallbackMetadata'] ?? [];
                $items = $callbackMetadata['Item'] ?? [];
                $mpesaAmount = null;
                $mpesaReceipt = null;
                $mpesaTimestamp = null;
                $mpesaPhone = null;

                foreach ($items as $item) {
                    if ($item['Name'] === 'Amount') {
                        $mpesaAmount = $item['Value'];
                    } elseif ($item['Name'] === 'MpesaReceiptNumber') {
                        $mpesaReceipt = $item['Value'];
                    } elseif ($item['Name'] === 'TransactionDate') {
                        $mpesaTimestamp = $item['Value'];
                    } elseif ($item['Name'] === 'PhoneNumber') {
                        $mpesaPhone = $item['Value'];
                    }
                }

                $payment->update([
                    'status' => PaymentStatus::Completed->value,
                    'paid_at' => now(),
                    'notes' => json_encode([
                        'mpesa_receipt' => $mpesaReceipt,
                        'mpesa_timestamp' => $mpesaTimestamp,
                        'mpesa_phone' => $mpesaPhone,
                        'mpesa_amount' => $mpesaAmount,
                    ]),
                ]);

                if ($payment->invoice) {
                    $payment->invoice->update(['status' => InvoiceStatus::Paid->value]);
                }

                Log::info('M-Pesa payment completed', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $payment->invoice_id,
                ]);

                return [
                    'success' => true,
                    'message' => 'Payment received',
                    'payment_id' => $payment->id,
                ];
            } else {
                $payment->update([
                    'status' => PaymentStatus::Failed->value,
                    'notes' => json_encode([
                        'result_code' => $resultCode,
                        'result_desc' => $stkCallback['ResultDesc'] ?? 'Unknown error',
                    ]),
                ]);

                Log::info('M-Pesa payment failed', [
                    'payment_id' => $payment->id,
                    'result_desc' => $stkCallback['ResultDesc'] ?? 'Unknown error',
                ]);

                return [
                    'success' => false,
                    'message' => 'Payment failed: ' . ($stkCallback['ResultDesc'] ?? 'Unknown error'),
                ];
            }
        } catch (\Exception $e) {
            Log::error('M-Pesa callback processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Callback processing failed',
            ];
        }
    }

    /**
     * Get M-Pesa access token (cached for 55 minutes)
     */
    private function getAccessToken(): ?string
    {
        return Cache::remember('mpesa_access_token', 55 * 60, function () {
            try {
                $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                    ->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

                if ($response->successful()) {
                    Log::info('M-Pesa token generated successfully', [
                        'environment' => $this->isProduction ? 'production' : 'sandbox',
                    ]);
                    return $response->json()['access_token'] ?? null;
                }

                Log::error('Failed to get M-Pesa access token', [
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('M-Pesa token generation failed', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Build callback URL using configured site_url (avoids route() issues behind proxies)
     */
    private function buildCallbackUrl(): string
    {
        return rtrim($this->siteUrl, '/') . '/webhooks/c2b';
    }

    /**
     * Test M-Pesa connection
     */
    public function testConnection(): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Failed to get access token',
                    'environment' => $this->isProduction ? 'production' : 'sandbox',
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'environment' => $this->isProduction ? 'production' : 'sandbox',
                'token_preview' => substr($token, 0, 10) . '...',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'environment' => $this->isProduction ? 'production' : 'sandbox',
            ];
        }
    }

    /**
     * Register callback URLs with Safaricom
     */
    public function registerCallbackUrls(string $responseType = 'Completed'): array
    {
        try {
            if ($this->isProduction && !str_starts_with($this->siteUrl, 'https')) {
                return [
                    'success' => false,
                    'message' => 'Production environment requires HTTPS callback URL',
                ];
            }

            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Failed to get access token for URL registration',
                ];
            }

            $callbackUrl = $this->buildCallbackUrl();

            $payload = [
                'ShortCode' => (string) $this->businessShortCode,
                'ResponseType' => $responseType,
                'ConfirmationURL' => $callbackUrl,
                'ValidationURL' => $callbackUrl,
            ];

            Log::info('M-Pesa URL Registration Request', [
                'shortcode' => (string) $this->businessShortCode,
                'callback_url' => $callbackUrl,
                'environment' => $this->isProduction ? 'production' : 'sandbox',
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/c2b/v2/registerurl", $payload);

            Log::info('M-Pesa URL Registration Response', [
                'status_code' => $response->status(),
                'response' => $response->json(),
            ]);

            if (!$response->successful()) {
                $responseBody = $response->json();
                $errorCode = $responseBody['errorCode'] ?? $response->status();
                $errorMsg = $responseBody['errorMessage'] ?? 'Unknown error';

                $hint = '';
                if ($errorCode === '400.003.02' || $errorCode === 400) {
                    $hint = ' (Validation error: check shortcode format, ensure callback URL is publicly accessible and HTTPS for production)';
                }

                return [
                    'success' => false,
                    'message' => "Registration failed: {$errorMsg}{$hint}",
                    'error_code' => $errorCode,
                    'response' => $responseBody,
                ];
            }

            $result = $response->json();

            return [
                'success' => true,
                'message' => 'URLs registered successfully',
                'response' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa URL registration failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'URL registration failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Simulate M-Pesa payment (sandbox only)
     */
    public function simulatePayment(string $phone, float $amount): array
    {
        try {
            if ($this->isProduction) {
                return [
                    'success' => false,
                    'message' => 'Payment simulation is only available in sandbox environment',
                ];
            }

            $token = $this->getAccessToken();
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'Failed to get access token',
                ];
            }

            $phone = $this->sanitizePhone($phone);

            $payload = [
                'CommandID' => 'CustomerPayBillOnline',
                'Amount' => (int) $amount,
                'Msisdn' => $phone,
                'ShortCode' => (string) $this->businessShortCode,
            ];

            Log::info('M-Pesa Simulate Payment Request', [
                'phone' => $phone,
                'amount' => $amount,
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/c2b/v2/simulate", $payload);

            Log::info('M-Pesa Simulate Payment Response', [
                'status_code' => $response->status(),
                'response' => $response->json(),
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Payment simulation failed: ' . ($response->json()['errorMessage'] ?? 'Unknown error'),
                    'response' => $response->json(),
                ];
            }

            return [
                'success' => true,
                'message' => 'Payment simulation sent. Check phone for M-Pesa prompt.',
                'response' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa payment simulation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment simulation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sanitize phone number to required format (254xxxxxxxxx)
     */
    private function sanitizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 9) {
            return '254' . $phone;
        } elseif (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
            return '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 3) === '254') {
            return $phone;
        }

        return '254' . $phone;
    }

    public function getMethod(): string
    {
        return 'mpesa';
    }

    public function isConfigured(): bool
    {
        $enabled = Setting::getValue('mpesa_enabled');
        return in_array($enabled, ['1', 'true', true], true)
            && !empty($this->consumerKey)
            && !empty($this->consumerSecret)
            && !empty($this->businessShortCode)
            && !empty($this->passkey);
    }
}
