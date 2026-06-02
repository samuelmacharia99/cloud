<?php

namespace App\Services\PaymentGateway;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerBrandingResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaService implements PaymentGatewayInterface
{
    protected ?string $consumerKey;

    protected ?string $consumerSecret;

    protected ?string $businessShortCode;

    protected ?string $passkey;

    protected bool $isProduction;

    protected string $baseUrl;

    protected string $siteUrl;

    protected bool $usesResellerConfig = false;

    protected ?string $platformConsumerKey = null;

    protected ?string $platformConsumerSecret = null;

    protected ?string $platformBusinessShortCode = null;

    protected ?string $platformPasskey = null;

    public function __construct(?User $reseller = null)
    {
        $mpesa = [];

        if ($reseller?->is_reseller) {
            $mpesa = $reseller->settings['mpesa'] ?? [];
        }

        $useReseller = ! empty($mpesa['business_shortcode'])
            && ! empty($mpesa['consumer_key'])
            && ! empty($mpesa['consumer_secret'])
            && ! empty($mpesa['passkey']);

        if ($useReseller) {
            $this->usesResellerConfig = true;
            $this->consumerKey = $mpesa['consumer_key'];
            $this->consumerSecret = $mpesa['consumer_secret'];
            $this->businessShortCode = (string) $mpesa['business_shortcode'];
            $this->passkey = $mpesa['passkey'];
            $branding = app(ResellerBrandingResolver::class)->forReseller($reseller);
            $this->siteUrl = $branding['portal_url'] ?? Setting::getValue('site_url', config('app.url'));
        } else {
            $this->consumerKey = Setting::getValue('mpesa_consumer_key', '');
            $this->consumerSecret = Setting::getValue('mpesa_consumer_secret', '');
            $this->businessShortCode = (string) Setting::getValue('mpesa_shortcode', '');
            $this->passkey = Setting::getValue('mpesa_passkey', '');
            $this->siteUrl = Setting::getValue('site_url', config('app.url'));
        }

        $this->platformConsumerKey = Setting::getValue('mpesa_consumer_key', '');
        $this->platformConsumerSecret = Setting::getValue('mpesa_consumer_secret', '');
        $this->platformBusinessShortCode = (string) Setting::getValue('mpesa_shortcode', '');
        $this->platformPasskey = Setting::getValue('mpesa_passkey', '');

        $this->isProduction = Setting::getValue('mpesa_environment', 'sandbox') === 'production';
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
            if (! $this->isConfigured()) {
                throw new \Exception('M-Pesa is not configured');
            }

            $phone = $this->sanitizePhone($customerData['phone'] ?? '');
            $chargeAmount = (float) ($customerData['charge_amount'] ?? $invoice->amountDue());
            $token = $this->getAccessToken();

            if (! $token) {
                throw new \Exception('Failed to get M-Pesa access token');
            }

            $timestamp = now()->format('YmdHis');
            $password = base64_encode($this->businessShortCode.$this->passkey.$timestamp);

            $payload = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) ceil($chargeAmount),
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

            if (! $response->successful()) {
                Log::error('M-Pesa STK Push failed', [
                    'invoice_id' => $invoice->id,
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ]);
                throw new \Exception('M-Pesa request failed: '.($response->json()['errorMessage'] ?? 'Unknown error'));
            }

            $data = $response->json();
            $responseCode = (string) ($data['ResponseCode'] ?? '');
            $checkoutRequestId = $data['CheckoutRequestID'] ?? null;

            if ($responseCode !== '0' || empty($checkoutRequestId)) {
                Log::warning('M-Pesa STK Push rejected', [
                    'invoice_id' => $invoice->id,
                    'response_code' => $responseCode,
                    'checkout_request_id' => $checkoutRequestId,
                    'response' => $data,
                ]);

                return [
                    'success' => false,
                    'message' => $data['ResponseDescription'] ?? $data['errorMessage'] ?? 'M-Pesa request was not accepted.',
                    'response_code' => $responseCode,
                ];
            }

            Payment::create([
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'amount' => $chargeAmount,
                'currency' => 'KES',
                'payment_method' => 'mpesa',
                'transaction_reference' => $checkoutRequestId,
                'status' => 'pending',
                'notes' => json_encode([
                    'response_code' => $responseCode,
                    'checkout_request_id' => $checkoutRequestId,
                ]),
            ]);

            return [
                'success' => true,
                'message' => 'Please check your phone for M-Pesa prompt',
                'checkout_request_id' => $checkoutRequestId,
                'response_code' => $responseCode,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa initiate failed', [
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
     * Verify M-Pesa payment via STK push query
     */
    public function verify(string $transactionReference): array
    {
        try {
            if (! $this->isConfigured()) {
                throw new \Exception('M-Pesa is not configured');
            }

            $token = $this->getAccessToken();
            if (! $token) {
                throw new \Exception('Failed to get access token');
            }

            $timestamp = now()->format('YmdHis');
            $password = base64_encode($this->businessShortCode.$this->passkey.$timestamp);

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

            if (! $response->successful()) {
                $data = $response->json();
                $errorCode = $data['errorCode'] ?? null;

                // Transaction within processing limit is expected - only log at debug level
                if ($errorCode === '500.001.1001') {
                    Log::debug('M-Pesa transaction still processing', [
                        'checkout_request_id' => $transactionReference,
                        'status_code' => $response->status(),
                    ]);
                } else {
                    Log::warning('M-Pesa query request failed', [
                        'checkout_request_id' => $transactionReference,
                        'status_code' => $response->status(),
                        'error_code' => $errorCode,
                        'response' => $data,
                    ]);
                }

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

            if ($isSuccessful) {
                return [
                    'success' => true,
                    'status' => 'completed',
                    'response_code' => $resultCode,
                    'message' => $data['ResultDesc'] ?? 'Payment completed',
                ];
            }

            return [
                'success' => false,
                'status' => 'failed',
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
            if (! $payment) {
                Log::warning('M-Pesa payment not found', ['checkout_request_id' => $checkoutRequestId]);

                return ['success' => false, 'message' => 'Payment not found'];
            }

            if ($resultCode == 0) {
                // Idempotency guard: if already completed, return success without re-processing
                if ($payment->status === PaymentStatus::Completed->value || $payment->status === 'completed') {
                    Log::info('M-Pesa callback: payment already completed (idempotency guard)', [
                        'payment_id' => $payment->id,
                        'checkout_request_id' => $checkoutRequestId,
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Payment already processed',
                        'payment_id' => $payment->id,
                    ];
                }

                // Wrap in a transaction with a pessimistic lock to prevent race conditions
                $result = DB::transaction(function () use ($payment, $stkCallback) {
                    // Re-fetch with lock to prevent concurrent duplicate processing
                    $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();

                    if (! $lockedPayment) {
                        return ['success' => false, 'message' => 'Payment record not found under lock'];
                    }

                    // Double-check after acquiring lock
                    if ($lockedPayment->status === PaymentStatus::Completed->value || $lockedPayment->status === 'completed') {
                        Log::info('M-Pesa callback: already completed after lock (race condition prevented)', [
                            'payment_id' => $lockedPayment->id,
                        ]);

                        return [
                            'success' => true,
                            'message' => 'Payment already processed',
                            'payment_id' => $lockedPayment->id,
                        ];
                    }

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

                    if ($mpesaAmount !== null && (int) ceil((float) $lockedPayment->amount) !== (int) $mpesaAmount) {
                        Log::error('M-Pesa callback rejected: amount mismatch', [
                            'payment_id' => $lockedPayment->id,
                            'expected' => (int) ceil((float) $lockedPayment->amount),
                            'received' => (int) $mpesaAmount,
                        ]);

                        $lockedPayment->update([
                            'status' => PaymentStatus::Failed->value,
                            'notes' => json_encode([
                                'result_code' => 'amount_mismatch',
                                'expected_amount' => (int) ceil((float) $lockedPayment->amount),
                                'received_amount' => (int) $mpesaAmount,
                            ]),
                        ]);

                        return [
                            'success' => false,
                            'message' => 'Payment amount does not match invoice total',
                        ];
                    }

                    $lockedPayment->update([
                        'status' => PaymentStatus::Completed->value,
                        'paid_at' => now(),
                        'notes' => json_encode([
                            'mpesa_receipt' => $mpesaReceipt,
                            'mpesa_timestamp' => $mpesaTimestamp,
                            'mpesa_phone' => $mpesaPhone,
                            'mpesa_amount' => $mpesaAmount,
                        ]),
                    ]);

                    if ($lockedPayment->payment_purpose === 'wallet_topup') {
                        app('wallet-service')->processTopupPayment($lockedPayment);

                        return ['success' => true, 'payment_id' => $lockedPayment->id, 'wallet_topup' => true];
                    }

                    if ($lockedPayment->invoice) {
                        $lockedPayment->invoice->update(['status' => InvoiceStatus::Paid->value]);

                        if ($lockedPayment->invoice->order) {
                            $lockedPayment->invoice->order->update([
                                'status' => 'paid',
                                'payment_status' => 'paid',
                            ]);
                        }
                    }

                    Log::info('M-Pesa payment completed', [
                        'payment_id' => $lockedPayment->id,
                        'invoice_id' => $lockedPayment->invoice_id,
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Payment received',
                        'payment_id' => $lockedPayment->id,
                    ];
                });

                return $result;
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
                    'message' => 'Payment failed: '.($stkCallback['ResultDesc'] ?? 'Unknown error'),
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
     * Initiate wallet top-up via M-Pesa STK push
     */
    public function initiateTopup(User $reseller, float $amount, string $phone, Invoice $topupInvoice): array
    {
        try {
            if (! $this->isConfigured()) {
                throw new \Exception('M-Pesa is not configured');
            }

            $phone = $this->sanitizePhone($phone);
            $token = $this->getAccessToken();

            if (! $token) {
                throw new \Exception('Failed to get M-Pesa access token');
            }

            $timestamp = now()->format('YmdHis');
            $password = base64_encode($this->businessShortCode.$this->passkey.$timestamp);

            $payload = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) ceil($amount),
                'PartyA' => $phone,
                'PartyB' => $this->businessShortCode,
                'PhoneNumber' => $phone,
                'CallBackURL' => $this->buildCallbackUrl(),
                'AccountReference' => "WALLET-{$reseller->id}",
                'TransactionDesc' => "Wallet top-up - {$amount} KES",
            ];

            Log::info('M-Pesa Wallet Topup STK Push Request', [
                'reseller_id' => $reseller->id,
                'phone' => $phone,
                'amount' => $amount,
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", $payload);

            if (! $response->successful()) {
                Log::error('M-Pesa wallet topup STK Push failed', [
                    'reseller_id' => $reseller->id,
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ]);
                throw new \Exception('M-Pesa request failed: '.($response->json()['errorMessage'] ?? 'Unknown error'));
            }

            $data = $response->json();
            $responseCode = (string) ($data['ResponseCode'] ?? '');
            $checkoutRequestId = $data['CheckoutRequestID'] ?? null;

            if ($responseCode !== '0' || empty($checkoutRequestId)) {
                Log::warning('M-Pesa wallet topup STK Push rejected', [
                    'reseller_id' => $reseller->id,
                    'response_code' => $responseCode,
                    'checkout_request_id' => $checkoutRequestId,
                    'response' => $data,
                ]);

                return [
                    'success' => false,
                    'message' => $data['ResponseDescription'] ?? $data['errorMessage'] ?? 'M-Pesa request was not accepted.',
                    'response_code' => $responseCode,
                ];
            }

            Payment::create([
                'user_id' => $reseller->id,
                'invoice_id' => $topupInvoice->id,
                'amount' => $amount,
                'currency' => 'KES',
                'payment_method' => 'mpesa',
                'payment_purpose' => 'wallet_topup',
                'transaction_reference' => $checkoutRequestId,
                'status' => 'pending',
                'notes' => json_encode([
                    'response_code' => $responseCode,
                    'checkout_request_id' => $checkoutRequestId,
                ]),
            ]);

            return [
                'success' => true,
                'message' => 'Please check your phone for M-Pesa prompt',
                'checkout_request_id' => $checkoutRequestId,
                'response_code' => $responseCode,
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa wallet topup initiate failed', [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Payment initiation failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get M-Pesa access token (cached for 55 minutes)
     */
    private function getAccessToken(): ?string
    {
        $cacheKey = $this->tokenCacheKey();
        $token = Cache::get($cacheKey);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = $this->requestAccessToken($this->consumerKey, $this->consumerSecret);
        if (is_string($token) && $token !== '') {
            Cache::put($cacheKey, $token, 55 * 60);

            return $token;
        }

        // If reseller credentials fail, fallback to platform credentials for continuity.
        if ($this->usesResellerConfig && $this->canFallbackToPlatformCredentials()) {
            Log::warning('M-Pesa reseller credentials failed, falling back to platform credentials', [
                'environment' => $this->isProduction ? 'production' : 'sandbox',
            ]);

            $fallbackKey = $this->tokenCacheKeyFor(
                $this->platformBusinessShortCode,
                $this->platformConsumerKey,
                $this->platformConsumerSecret,
                false
            );
            $fallbackToken = Cache::get($fallbackKey);
            if (! is_string($fallbackToken) || $fallbackToken === '') {
                $fallbackToken = $this->requestAccessToken($this->platformConsumerKey, $this->platformConsumerSecret);
                if (is_string($fallbackToken) && $fallbackToken !== '') {
                    Cache::put($fallbackKey, $fallbackToken, 55 * 60);
                }
            }

            if (is_string($fallbackToken) && $fallbackToken !== '') {
                $this->consumerKey = $this->platformConsumerKey;
                $this->consumerSecret = $this->platformConsumerSecret;
                $this->businessShortCode = (string) $this->platformBusinessShortCode;
                $this->passkey = $this->platformPasskey;
                $this->usesResellerConfig = false;

                return $fallbackToken;
            }
        }

        return null;
    }

    /**
     * Build callback URL using configured site_url (avoids route() issues behind proxies)
     */
    private function buildCallbackUrl(): string
    {
        $base = $this->resolveCallbackBaseUrl();
        $url = $base.'/webhooks/c2b';
        $token = Setting::getValue('mpesa_callback_token', '');

        if ($token !== '') {
            $url .= '?token='.urlencode($token);
        }

        return $url;
    }

    private function resolveCallbackBaseUrl(): string
    {
        $configured = trim((string) $this->siteUrl);
        if ($configured === '') {
            $configured = (string) config('app.url');
        }

        $configured = rtrim($configured, '/');
        $effective = $configured;

        // In HTTP context, trust the current host to avoid stale site_url misrouting callbacks.
        if (! app()->runningInConsole()) {
            try {
                $request = request();
                if ($request) {
                    $runtimeBase = rtrim($request->getSchemeAndHttpHost(), '/');
                    $configuredHost = parse_url($configured, PHP_URL_HOST);
                    $runtimeHost = parse_url($runtimeBase, PHP_URL_HOST);

                    if ($runtimeHost && $configuredHost && $runtimeHost !== $configuredHost) {
                        Log::warning('M-Pesa callback host mismatch detected; using runtime host', [
                            'configured_base' => $configured,
                            'runtime_base' => $runtimeBase,
                        ]);
                        $effective = $runtimeBase;
                    }
                }
            } catch (\Throwable $e) {
                // Keep configured fallback if request context is unavailable.
            }
        }

        if ($this->isProduction && str_starts_with($effective, 'http://')) {
            $effective = 'https://'.substr($effective, 7);
        }

        return rtrim($effective, '/');
    }

    /**
     * Test M-Pesa connection
     */
    public function testConnection(): array
    {
        try {
            $token = $this->getAccessToken();
            if (! $token) {
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
                'token_preview' => substr($token, 0, 10).'...',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: '.$e->getMessage(),
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
            if ($this->isProduction && ! str_starts_with($this->siteUrl, 'https')) {
                return [
                    'success' => false,
                    'message' => 'Production environment requires HTTPS callback URL',
                ];
            }

            $token = $this->getAccessToken();
            if (! $token) {
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

            if (! $response->successful()) {
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
                'message' => 'URL registration failed: '.$e->getMessage(),
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
            if (! $token) {
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

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Payment simulation failed: '.($response->json()['errorMessage'] ?? 'Unknown error'),
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
                'message' => 'Payment simulation failed: '.$e->getMessage(),
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
            return '254'.$phone;
        } elseif (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
            return '254'.substr($phone, 1);
        } elseif (substr($phone, 0, 3) === '254') {
            return $phone;
        }

        return '254'.$phone;
    }

    private function tokenCacheKey(): string
    {
        return $this->tokenCacheKeyFor(
            $this->businessShortCode,
            $this->consumerKey,
            $this->consumerSecret,
            $this->usesResellerConfig
        );
    }

    private function tokenCacheKeyFor(?string $shortcode, ?string $key, ?string $secret, bool $reseller): string
    {
        $seed = implode('|', [
            $this->isProduction ? 'prod' : 'sandbox',
            (string) $shortcode,
            (string) $key,
            (string) $secret,
            $reseller ? 'reseller' : 'platform',
        ]);

        return 'mpesa_access_token:'.sha1($seed);
    }

    private function requestAccessToken(?string $key, ?string $secret): ?string
    {
        if (empty($key) || empty($secret)) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($key, $secret)
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
    }

    private function canFallbackToPlatformCredentials(): bool
    {
        return ! empty($this->platformConsumerKey)
            && ! empty($this->platformConsumerSecret)
            && ! empty($this->platformBusinessShortCode)
            && ! empty($this->platformPasskey);
    }

    public function getMethod(): string
    {
        return 'mpesa';
    }

    public function isConfigured(): bool
    {
        $hasCredentials = ! empty($this->consumerKey)
            && ! empty($this->consumerSecret)
            && ! empty($this->businessShortCode)
            && ! empty($this->passkey);

        if ($this->usesResellerConfig) {
            return $hasCredentials;
        }

        $enabled = Setting::getValue('mpesa_enabled');

        return in_array($enabled, ['1', 'true', true], true) && $hasCredentials;
    }

    /**
     * Get comprehensive diagnostic information
     */
    public function getDiagnostics(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'environment' => $this->isProduction ? 'production' : 'sandbox',
            'base_url' => $this->baseUrl,
            'site_url' => $this->siteUrl,
            'callback_url' => $this->buildCallbackUrl(),
            'credentials_present' => [
                'consumer_key' => ! empty($this->consumerKey),
                'consumer_secret' => ! empty($this->consumerSecret),
                'business_shortcode' => ! empty($this->businessShortCode),
                'passkey' => ! empty($this->passkey),
            ],
            'shortcode' => $this->businessShortCode,
            'enabled' => Setting::getValue('mpesa_enabled'),
        ];
    }
}
