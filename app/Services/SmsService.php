<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;

class SmsService
{
    protected string $apiUrl = 'https://bulksms.talksasa.com/api/v3/sms/send';

    public function isConfigured(): bool
    {
        $enabled = \App\Models\Setting::getValue('sms_enabled');
        $token = \App\Models\Setting::getValue('sms_api_token');

        // Convert string "1"/"0" or "true"/"false" to boolean
        $enabledBool = in_array($enabled, ['1', 'true', true], true);

        \Log::info('SMS Service Configuration Check', [
            'enabled_raw' => $enabled,
            'enabled_bool' => $enabledBool,
            'token_present' => !empty($token),
            'is_configured' => $enabledBool && !empty($token)
        ]);

        return $enabledBool && !empty($token);
    }

    public function send(string|array $recipients, string $message, ?string $senderId = null): array
    {
        if (!$this->isConfigured()) {
            \Log::error('SMS service not configured', [
                'sms_enabled' => \App\Models\Setting::getValue('sms_enabled'),
                'token_set' => !empty(\App\Models\Setting::getValue('sms_api_token')),
            ]);
            return [
                'success' => false,
                'message' => 'SMS service is not configured.',
            ];
        }

        $token = \App\Models\Setting::getValue('sms_api_token');
        $senderId = $senderId ?? \App\Models\Setting::getValue('sms_sender_id', 'TalksasaCloud');

        // Normalize recipients to array, normalize each phone, then rejoin
        $recipientArray = is_array($recipients) ? $recipients : explode(',', $recipients);
        $normalizedRecipients = array_map(fn($phone) => PhoneHelper::normalize(trim($phone)), $recipientArray);
        $recipients = implode(',', $normalizedRecipients);

        \Log::info('Attempting to send SMS', [
            'recipients' => $recipients,
            'sender_id' => $senderId,
            'message_length' => strlen($message),
            'api_url' => $this->apiUrl,
        ]);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($this->apiUrl, [
                    'recipient' => $recipients,
                    'sender_id' => $senderId,
                    'type' => 'plain',
                    'message' => $message,
                ]);

            // If the request wasn't successful, throw an exception
            if (!$response->successful()) {
                throw new \Exception('SMS API returned status ' . $response->status() . ': ' . substr($response->body(), 0, 200));
            }

            // Try to parse JSON response
            try {
                $data = $response->json();
            } catch (\Exception $e) {
                // If JSON parsing fails, try to handle raw response
                $data = ['status' => 'success', 'message' => 'SMS sent', 'data' => $response->body()];
            }

            if ($response->successful() && isset($data['status']) && $data['status'] === 'success') {
                // Log success
                $recipientArray = explode(',', $recipients);
                foreach ($recipientArray as $recipient) {
                    SmsLog::create([
                        'recipient' => trim($recipient),
                        'message' => $message,
                        'sender_id' => $senderId,
                        'status' => 'sent',
                        'response' => json_encode($data),
                        'sent_by' => auth()->id(),
                        'created_at' => now(),
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'SMS sent successfully to ' . count($recipientArray) . ' recipient(s).',
                ];
            } else {
                // Log failure
                $recipientArray = explode(',', $recipients);
                foreach ($recipientArray as $recipient) {
                    SmsLog::create([
                        'recipient' => trim($recipient),
                        'message' => $message,
                        'sender_id' => $senderId,
                        'status' => 'failed',
                        'response' => json_encode($data),
                        'sent_by' => auth()->id(),
                        'created_at' => now(),
                    ]);
                }

                $errorMessage = $data['message'] ?? 'Failed to send SMS';
                return [
                    'success' => false,
                    'message' => $errorMessage,
                ];
            }
        } catch (\Exception $e) {
            // Log exception with full details
            \Log::error('SMS send exception', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'recipients' => $recipients,
            ]);

            $recipientArray = explode(',', $recipients);
            foreach ($recipientArray as $recipient) {
                SmsLog::create([
                    'recipient' => trim($recipient),
                    'message' => $message,
                    'sender_id' => $senderId,
                    'status' => 'failed',
                    'response' => $e->getMessage(),
                    'sent_by' => auth()->id() ?? 0,
                    'created_at' => now(),
                ]);
            }

            return [
                'success' => false,
                'message' => 'Failed to send SMS: ' . $e->getMessage(),
            ];
        }
    }

    public function sendTest(string $recipient, string $senderId): array
    {
        return $this->send($recipient, 'This is a test SMS from Talksasa Cloud.', $senderId);
    }
}
