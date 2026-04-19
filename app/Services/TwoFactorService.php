<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class TwoFactorService
{
    public function __construct(private SmsService $smsService)
    {
    }

    /**
     * Enable 2FA for a user and generate recovery codes
     */
    public function enable(User $user): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => $recoveryCodes,
        ]);

        Log::info('2FA enabled for user', [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);

        return $recoveryCodes;
    }

    /**
     * Disable 2FA for a user
     */
    public function disable(User $user): void
    {
        $user->update([
            'two_factor_enabled' => false,
            'two_factor_code' => null,
            'two_factor_code_expires_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        Log::info('2FA disabled for user', [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);
    }

    /**
     * Send 2FA code via SMS to user's primary phone
     */
    public function sendCode(User $user): bool
    {
        if (!$user->phone) {
            Log::error('Cannot send 2FA code: user has no phone number', [
                'user_id' => $user->id,
            ]);
            return false;
        }

        if (!$this->smsService->isConfigured()) {
            Log::error('Cannot send 2FA code: SMS service not configured', [
                'user_id' => $user->id,
            ]);
            return false;
        }

        try {
            // Generate 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store code with 5-minute expiry
            $user->update([
                'two_factor_code' => $code,
                'two_factor_code_expires_at' => now()->addMinutes(5),
            ]);

            // Send SMS
            $message = "Your Talksasa Cloud login code is: {$code}. Valid for 5 minutes. Do not share this code.";
            $smsResult = $this->smsService->send($user->phone, $message);

            // Log with SMS delivery status
            if ($smsResult['success'] ?? false) {
                Log::info('2FA code sent to user', [
                    'user_id' => $user->id,
                    'phone' => substr($user->phone, -4),
                    'code_length' => strlen($code),
                    'sms_success' => true,
                ]);
                return true;
            } else {
                Log::error('2FA SMS delivery failed', [
                    'user_id' => $user->id,
                    'phone' => substr($user->phone, -4),
                    'sms_message' => $smsResult['message'] ?? 'Unknown error',
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send 2FA code', [
                'user_id' => $user->id,
                'phone' => substr($user->phone, -4),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify 2FA code
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->two_factor_enabled) {
            return false;
        }

        // Check if code has expired
        if (!$user->two_factor_code_expires_at || now()->isAfter($user->two_factor_code_expires_at)) {
            Log::warning('2FA code expired', [
                'user_id' => $user->id,
            ]);
            return false;
        }

        // Check if code matches
        if ($user->two_factor_code !== $code) {
            Log::warning('Invalid 2FA code attempt', [
                'user_id' => $user->id,
            ]);
            return false;
        }

        // Clear the code
        $user->update([
            'two_factor_code' => null,
            'two_factor_code_expires_at' => null,
        ]);

        Log::info('2FA code verified successfully', [
            'user_id' => $user->id,
        ]);

        return true;
    }

    /**
     * Verify recovery code and remove it from the list
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        if (!$user->two_factor_enabled || !$user->two_factor_recovery_codes) {
            return false;
        }

        $recoveryCodes = $user->two_factor_recovery_codes;

        // Check if recovery code exists
        if (!in_array($code, $recoveryCodes)) {
            Log::warning('Invalid recovery code attempt', [
                'user_id' => $user->id,
            ]);
            return false;
        }

        // Remove the used recovery code
        $recoveryCodes = array_filter($recoveryCodes, fn($c) => $c !== $code);

        $user->update([
            'two_factor_recovery_codes' => array_values($recoveryCodes),
        ]);

        Log::info('Recovery code used', [
            'user_id' => $user->id,
            'codes_remaining' => count($recoveryCodes),
        ]);

        return true;
    }

    /**
     * Generate recovery codes
     */
    private function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8));
        }
        return $codes;
    }

    /**
     * Get remaining recovery codes count
     */
    public function getRecoveryCodesCount(User $user): int
    {
        if (!$user->two_factor_recovery_codes) {
            return 0;
        }
        return count($user->two_factor_recovery_codes);
    }
}
