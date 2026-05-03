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
    private const API_ENDPOINT = 'https://api.talksasa.com/sms/send';
    private const BULK_ENDPOINT = 'https://api.talksasa.com/sms/bulk';
    private const TIMEOUT = 30; // seconds
    private const RETRY_TIMES = 3;
    private const RETRY_DELAY = 1000; // milliseconds

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
            // Validate reseller SMS settings
            if (!$this->validateResellerSmsSettings($reseller, $logContext)) {
                return $this->createFailureResponse('SMS settings not configured', $logContext, $phoneNumber);
            }

            // Build payload
            $payload = $this->payloadService->buildSmsPayload($reseller, $phoneNumber, $message);
            Log::info('SMS Send: Payload built', array_merge($logContext, [
                'api_key_length' => strlen($payload['api_key']),
                'sender_id' => $payload['sender_id'],
                'timestamp' => $payload['timestamp'],
            ]));

            // Send SMS
            $response = $this->makeApiRequest($payload, $logContext);

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
            // Validate reseller SMS settings
            if (!$this->validateResellerSmsSettings($reseller, $logContext)) {
                return $this->createFailureResponse('SMS settings not configured', $logContext, implode(', ', $phoneNumbers));
            }

            // Build payload
            $payload = $this->payloadService->buildBulkSmsPayload($reseller, $phoneNumbers, $message);
            Log::info('SMS Bulk: Payload built', array_merge($logContext, [
                'api_key_length' => strlen($payload['api_key']),
                'sender_id' => $payload['sender_id'],
                'recipients' => $phoneNumbers,
            ]));

            // Send SMS
            $response = $this->makeApiRequest($payload, $logContext);

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
    private function validateResellerSmsSettings(User $reseller, array &$logContext): bool
    {
        $smsSettings = app(ResellerSettingsService::class)->getSmsSettings($reseller);

        $logContext['sms_enabled'] = $smsSettings['enabled'];
        $logContext['has_api_key'] = !empty($smsSettings['api_key']);
        $logContext['has_sender_id'] = !empty($smsSettings['sender_id']);

        if (empty($smsSettings['api_key'])) {
            Log::warning('SMS Send: Missing API key', $logContext);
            return false;
        }

        if (empty($smsSettings['sender_id'])) {
            Log::warning('SMS Send: Missing sender ID', $logContext);
            return false;
        }

        if (!$smsSettings['enabled']) {
            Log::warning('SMS Send: SMS not enabled for reseller', $logContext);
            return false;
        }

        return true;
    }

    /**
     * Make HTTP request to Talksasa API with retry logic
     */
    private function makeApiRequest(array $payload, array $logContext): Response
    {
        $attempt = 1;
        $lastError = null;

        while ($attempt <= self::RETRY_TIMES) {
            try {
                Log::info("SMS Send: API request (attempt {$attempt}/{self::RETRY_TIMES})", array_merge($logContext, [
                    'endpoint' => self::API_ENDPOINT,
                    'timeout' => self::TIMEOUT,
                ]));

                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
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

        try {
            $responseData = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('SMS Send: Failed to parse response JSON', array_merge($logContext, [
                'error' => $e->getMessage(),
                'raw_body' => $response->body(),
            ]));
            $responseData = ['raw_body' => $response->body()];
        }

        // Determine success based on status code and response
        if ($response->successful()) {
            $status = 'sent';
            Log::info('SMS Send: Success', array_merge($logContext, [
                'status' => $status,
                'sms_id' => $responseData['sms_id'] ?? null,
            ]));
        } else {
            Log::error('SMS Send: Failed', array_merge($logContext, [
                'status_code' => $response->status(),
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
            'sms_id' => $responseData['sms_id'] ?? null,
            'message' => $status === 'sent' ? 'SMS sent successfully' : 'SMS delivery failed',
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

        try {
            $responseData = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('SMS Bulk: Failed to parse response JSON', array_merge($logContext, [
                'error' => $e->getMessage(),
                'raw_body' => $response->body(),
            ]));
            $responseData = ['raw_body' => $response->body()];
        }

        // Determine success based on status code
        if ($response->successful()) {
            $status = 'sent';
            Log::info('SMS Bulk: Success', array_merge($logContext, [
                'status' => $status,
                'campaign_id' => $responseData['campaign_id'] ?? null,
                'accepted' => $responseData['accepted'] ?? count($phoneNumbers),
            ]));
        } else {
            Log::error('SMS Bulk: Failed', array_merge($logContext, [
                'status_code' => $response->status(),
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
                        'campaign_id' => $responseData['campaign_id'] ?? null,
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
            'campaign_id' => $responseData['campaign_id'] ?? null,
            'accepted' => $responseData['accepted'] ?? ($status === 'sent' ? count($phoneNumbers) : 0),
            'message' => $status === 'sent' ? 'Bulk SMS sent successfully' : 'Bulk SMS delivery failed',
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
