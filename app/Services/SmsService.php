<?php

namespace App\Services;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;

class SmsService
{
    protected string $apiUrl = 'https://bulksms.talksasa.com/api/v3/sms/send';

    public function isConfigured(): bool
    {
        $enabled = \App\Models\Setting::getValue('sms_enabled');
        $token = \App\Models\Setting::getValue('sms_api_token');

        return $enabled && !empty($token);
    }

    public function send(string|array $recipients, string $message, ?string $senderId = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'SMS service is not configured.',
            ];
        }

        $token = \App\Models\Setting::getValue('sms_api_token');
        $senderId = $senderId ?? \App\Models\Setting::getValue('sms_sender_id', 'TalksasaCloud');

        // Ensure recipients is a string
        if (is_array($recipients)) {
            $recipients = implode(',', $recipients);
        }

        try {
            $response = Http::withToken($token)->post($this->apiUrl, [
                'recipient' => $recipients,
                'sender_id' => $senderId,
                'type' => 'plain',
                'message' => $message,
            ]);

            $data = $response->json();

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
            // Log exception
            $recipientArray = explode(',', $recipients);
            foreach ($recipientArray as $recipient) {
                SmsLog::create([
                    'recipient' => trim($recipient),
                    'message' => $message,
                    'sender_id' => $senderId,
                    'status' => 'failed',
                    'response' => $e->getMessage(),
                    'sent_by' => auth()->id(),
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
