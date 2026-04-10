<?php

namespace App\Services\PaymentGateway;

use App\Models\Invoice;
use App\Models\Payment;
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

    public function __construct()
    {
        $this->consumerKey = config('payment.mpesa.consumer_key') ?? '';
        $this->consumerSecret = config('payment.mpesa.consumer_secret') ?? '';
        $this->businessShortCode = config('payment.mpesa.business_short_code') ?? '';
        $this->passkey = config('payment.mpesa.pass_key') ?? '';
        $this->isProduction = config('payment.mpesa.is_production', false);
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
            // Get access token
            $token = $this->getAccessToken();
            if (!$token) {
                throw new \Exception('Failed to get M-Pesa access token');
            }

            // Generate timestamp
            $timestamp = now()->format('YmdHis');

            // Generate password
            $password = base64_encode(
                $this->businessShortCode . $this->passkey . $timestamp
            );

            // Prepare request
            $payload = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) ceil($invoice->total),
                'PartyA' => $this->sanitizePhoneNumber($customerData['phone'] ?? ''),
                'PartyB' => $this->businessShortCode,
                'PhoneNumber' => $this->sanitizePhoneNumber($customerData['phone'] ?? ''),
                'CallBackURL' => route('payment.mpesa.callback'),
                'AccountReference' => $invoice->invoice_number,
                'TransactionDesc' => "Invoice {$invoice->invoice_number}",
            ];

            // Make request to M-Pesa
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", $payload);

            if (!$response->successful()) {
                Log::error('M-Pesa STK Push failed', [
                    'invoice_id' => $invoice->id,
                    'response' => $response->json(),
                ]);
                throw new \Exception('M-Pesa request failed: ' . $response->json()['errorMessage'] ?? 'Unknown error');
            }

            $data = $response->json();

            // Store pending payment
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
     * Verify M-Pesa payment (called from callback or query)
     */
    public function verify(string $transactionReference): array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                throw new \Exception('Failed to get access token');
            }

            $timestamp = now()->format('YmdHis');
            $password = base64_encode(
                $this->businessShortCode . $this->passkey . $timestamp
            );

            $payload = [
                'BusinessShortCode' => $this->businessShortCode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $transactionReference,
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
            ])->post("{$this->baseUrl}/mpesa/stkpushquery/v1/query", $payload);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status' => 'pending',
                    'message' => 'Query failed',
                ];
            }

            $data = $response->json();

            // Response codes: 0 = successful, 1032 = request timeout, others = various errors
            $isSuccessful = ($data['ResponseCode'] ?? null) === '0';

            return [
                'success' => $isSuccessful,
                'status' => $isSuccessful ? 'completed' : 'pending',
                'amount' => $data['CallbackMetadata']['Item'][0]['Value'] ?? null,
                'receipt' => $data['CallbackMetadata']['Item'][1]['Value'] ?? null,
                'timestamp' => $data['CallbackMetadata']['Item'][3]['Value'] ?? null,
                'phone' => $data['CallbackMetadata']['Item'][4]['Value'] ?? null,
                'response_code' => $data['ResponseCode'] ?? null,
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
            $callbackMetadata = $stkCallback['CallbackMetadata'] ?? [];

            // Find payment by transaction reference
            $payment = Payment::where('transaction_reference', $checkoutRequestId)->first();
            if (!$payment) {
                return ['success' => false, 'message' => 'Payment not found'];
            }

            // Check result code (0 = success)
            if ($resultCode == 0) {
                // Payment successful
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

                // Update payment
                $payment->update([
                    'status' => 'completed',
                    'paid_at' => now(),
                    'notes' => json_encode([
                        'mpesa_receipt' => $mpesaReceipt,
                        'mpesa_timestamp' => $mpesaTimestamp,
                        'mpesa_phone' => $mpesaPhone,
                        'mpesa_amount' => $mpesaAmount,
                    ]),
                ]);

                // Update invoice
                $payment->invoice->update(['status' => 'paid']);

                return [
                    'success' => true,
                    'message' => 'Payment received',
                    'payment_id' => $payment->id,
                ];
            } else {
                // Payment failed
                $payment->update([
                    'status' => 'failed',
                    'notes' => json_encode([
                        'result_code' => $resultCode,
                        'result_desc' => $stkCallback['ResultDesc'] ?? 'Unknown error',
                    ]),
                ]);

                return [
                    'success' => false,
                    'message' => 'Payment failed: ' . ($stkCallback['ResultDesc'] ?? 'Unknown error'),
                ];
            }
        } catch (\Exception $e) {
            Log::error('M-Pesa callback processing failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return [
                'success' => false,
                'message' => 'Callback processing failed',
            ];
        }
    }

    /**
     * Get M-Pesa access token
     */
    private function getAccessToken(): ?string
    {
        try {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

            if ($response->successful()) {
                return $response->json()['access_token'] ?? null;
            }

            Log::error('Failed to get M-Pesa access token', [
                'response' => $response->json(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('M-Pesa token generation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Sanitize phone number to required format (254xxxxxxxxx)
     */
    private function sanitizePhoneNumber(string $phone): string
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
        return !empty($this->consumerKey) &&
               !empty($this->consumerSecret) &&
               !empty($this->businessShortCode) &&
               !empty($this->passkey);
    }
}
