<?php

namespace App\Services;

use App\Models\User;

class SmsPayloadService
{
    public function buildSmsPayload(User $reseller, string $phoneNumber, string $message): array
    {
        $smsSettings = app(ResellerSettingsService::class)->getSmsSettings($reseller);

        return [
            'api_key' => $smsSettings['api_key'],
            'sender_id' => $smsSettings['sender_id'],
            'phone' => $this->normalizePhoneNumber($phoneNumber),
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function buildBulkSmsPayload(User $reseller, array $phoneNumbers, string $message): array
    {
        $smsSettings = app(ResellerSettingsService::class)->getSmsSettings($reseller);

        return [
            'api_key' => $smsSettings['api_key'],
            'sender_id' => $smsSettings['sender_id'],
            'recipients' => array_map(fn($phone) => $this->normalizePhoneNumber($phone), $phoneNumbers),
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function buildWebhookPayload(string $smsId, string $status, string $phoneNumber): array
    {
        return [
            'sms_id' => $smsId,
            'status' => $status, // delivered, failed, pending
            'phone' => $phoneNumber,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (!str_starts_with($phone, '+')) {
            if (str_starts_with($phone, '254')) {
                $phone = '+' . $phone;
            } elseif (str_starts_with($phone, '0')) {
                $phone = '+254' . substr($phone, 1);
            } else {
                $phone = '+254' . $phone;
            }
        }

        return $phone;
    }
}
