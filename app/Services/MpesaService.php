<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    private const API_SANDBOX = 'https://sandbox.safaricom.co.ke';
    private const API_PRODUCTION = 'https://api.safaricom.co.ke';
    private const ACCESS_TOKEN_CACHE_KEY = 'mpesa_access_token';
    private const ACCESS_TOKEN_CACHE_MINUTES = 55;

    public function isConfigured(): bool
    {
        $enabled = \App\Models\Setting::getValue('mpesa_enabled', 'false') === 'true';
        $consumerKey = \App\Models\Setting::getValue('mpesa_consumer_key', '');
        $consumerSecret = \App\Models\Setting::getValue('mpesa_consumer_secret', '');

        return $enabled && !empty($consumerKey) && !empty($consumerSecret);
    }

    public function getAccessToken(): string
    {
        return Cache::remember(self::ACCESS_TOKEN_CACHE_KEY, self::ACCESS_TOKEN_CACHE_MINUTES * 60, function () {
            $baseUrl = $this->getBaseUrl();
            $consumerKey = \App\Models\Setting::getValue('mpesa_consumer_key', '');
            $consumerSecret = \App\Models\Setting::getValue('mpesa_consumer_secret', '');

            try {
                $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                    ->get("{$baseUrl}/oauth/v1/generate?grant_type=client_credentials");

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('M-Pesa: Failed to get access token', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new \Exception('Failed to get M-Pesa access token');
            } catch (\Exception $e) {
                Log::error('M-Pesa: Exception getting access token', ['error' => $e->getMessage()]);
                throw $e;
            }
        });
    }

    public function stkPush(Invoice $invoice, string $phone, float $amount): array
    {
        try {
            if (!$this->isConfigured()) {
                return ['success' => false, 'message' => 'M-Pesa is not configured'];
            }

            $phone = $this->normalizePhone($phone);
            $amount = (int) ceil($amount);

            if ($amount < 1) {
                return ['success' => false, 'message' => 'Amount must be at least 1'];
            }

            $timestamp = now()->format('YmdHis');
            $shortcode = \App\Models\Setting::getValue('mpesa_shortcode', '');
            $passkey = \App\Models\Setting::getValue('mpesa_passkey', '');

            $password = base64_encode($shortcode . $passkey . $timestamp);

            $baseUrl = $this->getBaseUrl();
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->post("{$baseUrl}/mpesa/stkpush/v1/processrequest", [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => $amount,
                    'PartyA' => $phone,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $phone,
                    'CallBackURL' => url('/mpesa/callback'),
                    'AccountReference' => $invoice->invoice_number,
                    'TransactionDesc' => 'Invoice ' . $invoice->invoice_number,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['ResponseCode']) && $data['ResponseCode'] === '0') {
                    return [
                        'success' => true,
                        'checkoutRequestId' => $data['CheckoutRequestID'],
                        'message' => 'STK push initiated. Enter your M-Pesa PIN on your phone.',
                    ];
                }
            }

            Log::error('M-Pesa: STK push failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'invoice_id' => $invoice->id,
            ]);

            return [
                'success' => false,
                'message' => 'Failed to initiate payment. Please try again.',
            ];
        } catch (\Exception $e) {
            Log::error('M-Pesa: Exception during STK push', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoice->id,
            ]);

            return [
                'success' => false,
                'message' => 'Payment initiation error. Please try again.',
            ];
        }
    }

    public function processCallback(array $data): void
    {
        try {
            $body = $data['Body'] ?? [];
            $stkCallback = $body['stkCallback'] ?? [];

            $resultCode = $stkCallback['ResultCode'] ?? null;
            $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
            $callbackMetadata = $stkCallback['CallbackMetadata'] ?? [];

            // Find payment by temporary transaction reference (CheckoutRequestID)
            $payment = Payment::where('transaction_reference', $checkoutRequestId)->first();

            if (!$payment) {
                Log::warning('M-Pesa: Callback received for unknown payment', [
                    'checkoutRequestId' => $checkoutRequestId,
                ]);
                return;
            }

            $invoice = $payment->invoice;

            if ($resultCode === 0) {
                // Success
                $metadata = collect($callbackMetadata['Item'] ?? [])->keyBy('Name')->map->Value;
                $receiptNumber = $metadata['MpesaReceiptNumber'] ?? null;
                $amount = $metadata['Amount'] ?? 0;
                $transactionDate = $metadata['TransactionDate'] ?? null;

                $payment->update([
                    'status' => PaymentStatus::Completed,
                    'transaction_reference' => $receiptNumber,
                    'paid_at' => now(),
                    'notes' => 'M-Pesa receipt: ' . $receiptNumber,
                ]);

                // Check if invoice is fully paid
                $paidAmount = $invoice->payments()
                    ->where('status', PaymentStatus::Completed)
                    ->sum('amount');

                if ($paidAmount >= $invoice->total) {
                    $invoice->update([
                        'status' => \App\Enums\InvoiceStatus::Paid,
                        'paid_date' => now(),
                    ]);

                    // Provision services
                    $services = $invoice->items->map->service->filter();
                    foreach ($services as $service) {
                        if ($service && $service->status->value === 'pending') {
                            app(\App\Services\Provisioning\ProvisioningService::class)->provision($service);
                        }
                    }
                }

                Log::info('M-Pesa: Payment completed', [
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'receipt' => $receiptNumber,
                ]);
            } else {
                // Failure
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'notes' => 'M-Pesa callback error code: ' . $resultCode,
                ]);

                Log::warning('M-Pesa: Payment failed', [
                    'payment_id' => $payment->id,
                    'result_code' => $resultCode,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('M-Pesa: Exception processing callback', [
                'error' => $e->getMessage(),
                'data' => json_encode($data),
            ]);
        }
    }

    public function normalizePhone(string $phone): string
    {
        // Remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-()]+/', '', $phone);

        // If it starts with +254, keep it as is (but convert to just 254)
        if (str_starts_with($phone, '+254')) {
            return '254' . substr($phone, 4);
        }

        // If it starts with 254, keep it as is
        if (str_starts_with($phone, '254')) {
            return $phone;
        }

        // If it starts with 0, convert to 254
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        // Otherwise assume it's missing country code
        return '254' . $phone;
    }

    private function getBaseUrl(): string
    {
        $environment = \App\Models\Setting::getValue('mpesa_environment', 'sandbox');
        return $environment === 'production' ? self::API_PRODUCTION : self::API_SANDBOX;
    }
}
