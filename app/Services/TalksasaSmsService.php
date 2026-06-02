<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class TalksasaSmsService
{
    private const API_ENDPOINT = 'https://bulksms.talksasa.com/api/v3/sms/send';
    private const CAMPAIGN_ENDPOINT = 'https://bulksms.talksasa.com/api/v3/sms/campaign';
    private const TIMEOUT = 30; // seconds
    private const RETRY_TIMES = 3;
    private const RETRY_DELAY = 1000; // milliseconds
    private const SMS_TYPE = 'plain'; // Talksasa SMS type

    public function __construct(
        private SmsPayloadService $payloadService
    ) {}

    /**
     * Send single SMS with comprehensive logging
     */
    public function sendSms(User $reseller, string $phoneNumber, string $message): array
    {
        $phoneNumber = $this->payloadService->normalizePhone($phoneNumber);
        $logContext = [
            'reseller_id' => $reseller->id,
            'reseller_email' => $reseller->email,
            'recipient' => $phoneNumber,
            'message_length' => strlen($message),
        ];

        Log::info('SMS Send: Building payload', $logContext);

        try {
            if (! $reseller->is_reseller) {
                Log::warning('SMS Send: Non-reseller context rejected', array_merge($logContext, [
                    'is_reseller' => (bool) $reseller->is_reseller,
                ]));

                return $this->createFailureResponse('SMS sender must be a reseller account', $logContext, $phoneNumber);
            }

            // Validate reseller SMS settings
            $resellerSms = app(ResellerSettingsService::class)->getSmsSettings($reseller);
            if (! $this->validateResellerSmsSettings($reseller, $resellerSms, $logContext)) {
                return $this->createFailureResponse('SMS settings not configured', $logContext, $phoneNumber);
            }

            // Build Talksasa API payload
            $payload = $this->buildTalksasaPayload($phoneNumber, $message, trim((string) $resellerSms['sender_id']));
            Log::info('SMS Send: Payload built', array_merge($logContext, [
                'recipient' => $payload['recipient'],
                'sender_id' => $payload['sender_id'],
                'type' => $payload['type'],
                'message_length' => strlen($payload['message']),
            ]));

            // Send SMS
            $response = $this->makeApiRequest($payload, trim((string) $resellerSms['api_key']), $logContext);

            // Process response
            return $this->processSmsResponse($response, $reseller, $phoneNumber, $message, $payload, $logContext);
        } catch (Exception $e) {
            Log::error('SMS Send: Exception occurred', array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception' => class_basename($e),
                'trace' => $e->getTraceAsString(),
            ]));

            return $this->createFailureResponse($e->getMessage(), $logContext, $phoneNumber);
        }
    }

    /**
     * Send bulk SMS with comprehensive logging
     */
    public function sendBulkSms(User $reseller, array $phoneNumbers, string $message): array
    {
        $phoneNumbers = array_map(fn($phone) => $this->payloadService->normalizePhone($phone), $phoneNumbers);
        $logContext = [
            'reseller_id' => $reseller->id,
            'reseller_email' => $reseller->email,
            'recipient_count' => count($phoneNumbers),
            'message_length' => strlen($message),
        ];

        Log::info('SMS Bulk: Building payload', $logContext);

        try {
            if (! $reseller->is_reseller) {
                Log::warning('SMS Bulk: Non-reseller context rejected', array_merge($logContext, [
                    'is_reseller' => (bool) $reseller->is_reseller,
                ]));

                return $this->createFailureResponse('SMS sender must be a reseller account', $logContext, implode(', ', $phoneNumbers));
            }

            // Validate reseller SMS settings
            $resellerSms = app(ResellerSettingsService::class)->getSmsSettings($reseller);
            if (! $this->validateResellerSmsSettings($reseller, $resellerSms, $logContext)) {
                return $this->createFailureResponse('SMS settings not configured', $logContext, implode(', ', $phoneNumbers));
            }

            // Build Talksasa API payload
            $payload = $this->buildTalksasaBulkPayload($phoneNumbers, $message, trim((string) $resellerSms['sender_id']));
            Log::info('SMS Bulk: Payload built', array_merge($logContext, [
                'recipient_count' => count($phoneNumbers),
                'sender_id' => $payload['sender_id'],
                'type' => $payload['type'],
                'message_length' => strlen($payload['message']),
            ]));

            // Send SMS
            $response = $this->makeApiRequest($payload, trim((string) $resellerSms['api_key']), $logContext);

            // Process response
            return $this->processBulkSmsResponse($response, $reseller, $phoneNumbers, $message, $payload, $logContext);
        } catch (Exception $e) {
            Log::error('SMS Bulk: Exception occurred', array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception' => class_basename($e),
                'trace' => $e->getTraceAsString(),
            ]));

            return $this->createFailureResponse($e->getMessage(), $logContext, implode(', ', $phoneNumbers));
        }
    }

    /**
     * Validate reseller SMS settings are configured
     */
    private function validateResellerSmsSettings(User $reseller, array $smsSettings, array &$logContext): bool
    {
        $enabled = (bool) ($smsSettings['enabled'] ?? false);
        $apiKey = trim((string) ($smsSettings['api_key'] ?? ''));
        $senderId = trim((string) ($smsSettings['sender_id'] ?? ''));

        $logContext['sms_enabled'] = $enabled;
        $logContext['has_api_key'] = $apiKey !== '';
        $logContext['has_sender_id'] = $senderId !== '';
        $logContext['sms_source'] = 'reseller';

        if ($apiKey === '') {
            Log::warning('SMS Send: Missing API key', $logContext);
            return false;
        }

        if ($senderId === '') {
            Log::warning('SMS Send: Missing sender ID', $logContext);
            return false;
        }

        if (! $enabled) {
            Log::warning('SMS Send: SMS not enabled for reseller', $logContext);
            return false;
        }

        return true;
    }

    /**
     * Build Talksasa API payload for single SMS
     */
    private function buildTalksasaPayload(string $phoneNumber, string $message, string $senderId): array
    {
        return [
            'recipient' => $phoneNumber,
            'sender_id' => $senderId,
            'type' => self::SMS_TYPE,
            'message' => $message,
        ];
    }

    /**
     * Build Talksasa API payload for bulk SMS
     */
    private function buildTalksasaBulkPayload(array $phoneNumbers, string $message, string $senderId): array
    {
        return [
            'recipient' => implode(',', $phoneNumbers),
            'sender_id' => $senderId,
            'type' => self::SMS_TYPE,
            'message' => $message,
        ];
    }

    /**
     * Make HTTP request to Talksasa API with retry logic
     */
    private function makeApiRequest(array $payload, string $apiKey, array $logContext): Response
    {
        $attempt = 1;
        $lastError = null;

        while ($attempt <= self::RETRY_TIMES) {
            try {
                Log::info("SMS Send: API request (attempt {$attempt}/{self::RETRY_TIMES})", array_merge($logContext, [
                    'endpoint' => self::API_ENDPOINT,
                    'timeout' => self::TIMEOUT,
                    'recipient' => $payload['recipient'] ?? 'unknown',
                    'type' => $payload['type'] ?? 'unknown',
                ]));

                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $apiKey,
                        'User-Agent' => 'TalksasaCloud/1.0',
                    ])
                    ->post(self::API_ENDPOINT, $payload);

                Log::info('SMS Send: API response received', array_merge($logContext, [
                    'status_code' => $response->status(),
                    'response_size' => strlen($response->body()),
                    'attempt' => $attempt,
                ]));

                return $response;
            } catch (Exception $e) {
                $lastError = $e;
                Log::warning("SMS Send: API request failed (attempt {$attempt}/{self::RETRY_TIMES})", array_merge($logContext, [
                    'error' => $e->getMessage(),
                    'retry_delay_ms' => self::RETRY_DELAY,
                ]));

                if ($attempt < self::RETRY_TIMES) {
                    usleep(self::RETRY_DELAY * 1000);
                }

                $attempt++;
            }
        }

        throw new Exception("API request failed after {self::RETRY_TIMES} attempts: " . $lastError?->getMessage());
    }

    /**
     * Process single SMS API response
     */
    private function processSmsResponse(Response $response, User $reseller, string $phoneNumber, string $message, array $payload, array $logContext): array
    {
        $status = 'failed';
        $responseData = [];
        $talksasaStatus = null;

        try {
            $responseData = $response->json() ?? [];
            $talksasaStatus = $responseData['status'] ?? null;
        } catch (Exception $e) {
            Log::error('SMS Send: Failed to parse response JSON', array_merge($logContext, [
                'error' => $e->getMessage(),
                'raw_body' => $response->body(),
            ]));
            $responseData = ['raw_body' => $response->body()];
        }

        // Determine success based on Talksasa response status and HTTP status code
        // Talksasa returns 202 (Accepted) for successful async SMS
        // Response status can be "success", "accepted", or "error"
        if (($talksasaStatus === 'success' || $talksasaStatus === 'accepted') && $response->successful()) {
            $status = 'sent';
            Log::info('SMS Send: Success', array_merge($logContext, [
                'status' => $status,
                'http_status_code' => $response->status(),
                'talksasa_status' => $talksasaStatus,
                'queue_uid' => $responseData['data']['queue_uid'] ?? $responseData['queue_uid'] ?? null,
                'response_data' => $responseData['data'] ?? $responseData,
            ]));
        } else {
            Log::error('SMS Send: Failed', array_merge($logContext, [
                'status_code' => $response->status(),
                'talksasa_status' => $talksasaStatus,
                'message' => $responseData['message'] ?? null,
                'response' => $responseData,
                'reason' => $response->reason(),
            ]));
        }

        // Log to database
        try {
            SmsLog::create([
                'recipient' => $phoneNumber,
                'message' => $message,
                'sender_id' => $payload['sender_id'],
                'status' => $status,
                'response' => json_encode($responseData),
                'sent_by' => $reseller->id,
                'created_at' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('SMS Send: Failed to log SMS', array_merge($logContext, [
                'error' => $e->getMessage(),
            ]));
        }

        return [
            'success' => $status === 'sent',
            'status' => $status,
            'talksasa_status' => $talksasaStatus,
            'queue_uid' => $responseData['data']['queue_uid'] ?? $responseData['queue_uid'] ?? null,
            'message' => $status === 'sent' ? 'SMS sent successfully' : ($responseData['message'] ?? 'SMS delivery failed'),
            'response' => $responseData,
        ];
    }

    /**
     * Process bulk SMS API response
     */
    private function processBulkSmsResponse(Response $response, User $reseller, array $phoneNumbers, string $message, array $payload, array $logContext): array
    {
        $status = 'failed';
        $responseData = [];
        $talksasaStatus = null;

        try {
            $responseData = $response->json() ?? [];
            $talksasaStatus = $responseData['status'] ?? null;
        } catch (Exception $e) {
            Log::error('SMS Bulk: Failed to parse response JSON', array_merge($logContext, [
                'error' => $e->getMessage(),
                'raw_body' => $response->body(),
            ]));
            $responseData = ['raw_body' => $response->body()];
        }

        // Determine success based on Talksasa response status and HTTP status code
        // Talksasa returns 202 (Accepted) for successful async SMS
        if (($talksasaStatus === 'success' || $talksasaStatus === 'accepted') && $response->successful()) {
            $status = 'sent';
            Log::info('SMS Bulk: Success', array_merge($logContext, [
                'status' => $status,
                'http_status_code' => $response->status(),
                'talksasa_status' => $talksasaStatus,
                'queue_uid' => $responseData['data']['queue_uid'] ?? $responseData['queue_uid'] ?? null,
                'response_data' => $responseData['data'] ?? $responseData,
            ]));
        } else {
            Log::error('SMS Bulk: Failed', array_merge($logContext, [
                'status_code' => $response->status(),
                'talksasa_status' => $talksasaStatus,
                'message' => $responseData['message'] ?? null,
                'response' => $responseData,
                'reason' => $response->reason(),
            ]));
        }

        // Log to database for each recipient
        try {
            foreach ($phoneNumbers as $phoneNumber) {
                SmsLog::create([
                    'recipient' => $phoneNumber,
                    'message' => $message,
                    'sender_id' => $payload['sender_id'],
                    'status' => $status,
                    'response' => json_encode([
                        'talksasa_status' => $talksasaStatus,
                        'bulk_send' => true,
                    ]),
                    'sent_by' => $reseller->id,
                    'created_at' => now(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('SMS Bulk: Failed to log SMS records', array_merge($logContext, [
                'error' => $e->getMessage(),
            ]));
        }

        return [
            'success' => $status === 'sent',
            'status' => $status,
            'talksasa_status' => $talksasaStatus,
            'queue_uid' => $responseData['data']['queue_uid'] ?? $responseData['queue_uid'] ?? null,
            'recipient_count' => count($phoneNumbers),
            'message' => $status === 'sent' ? 'Bulk SMS sent successfully' : ($responseData['message'] ?? 'Bulk SMS delivery failed'),
            'response' => $responseData,
        ];
    }

    /**
     * Create failure response
     */
    private function createFailureResponse(string $error, array $logContext, string $recipient): array
    {
        Log::error('SMS Send: Returning failure response', array_merge($logContext, [
            'error' => $error,
        ]));

        return [
            'success' => false,
            'status' => 'failed',
            'message' => $error,
            'sms_id' => null,
            'response' => [],
        ];
    }

}
