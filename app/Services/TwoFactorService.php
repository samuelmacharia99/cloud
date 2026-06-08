<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorService
{
    public function __construct(private AuthCodeSmsService $authCodeSms) {}

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
        if (! $this->authCodeSms->canSend($user)) {
            Log::error('Cannot send 2FA code: phone or SMS not available', [
                'user_id' => $user->id,
                'has_phone' => (bool) $user->phone,
                'sms_configured' => $this->authCodeSms->isConfiguredFor($user),
            ]);

            return false;
        }

        try {
            // Generate 6-digit code
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store hashed code with 5-minute expiry
            $user->update([
                'two_factor_code' => Hash::make($code),
                'two_factor_code_expires_at' => now()->addMinutes(5),
            ]);

            $siteName = $this->authCodeSms->siteNameFor($user);
            $message = "Your {$siteName} login code is: {$code}. Valid for 5 minutes. Do not share this code.";
            $smsResult = $this->authCodeSms->send($user, $message);

            if ($smsResult['success'] ?? false) {
                Log::info('2FA code sent to user', [
                    'user_id' => $user->id,
                    'phone' => substr((string) $user->phone, -4),
                    'channel' => $smsResult['channel'] ?? null,
                ]);

                return true;
            }

            Log::error('2FA SMS delivery failed', [
                'user_id' => $user->id,
                'phone' => substr((string) $user->phone, -4),
                'sms_message' => $smsResult['message'] ?? 'Unknown error',
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to send 2FA code', [
                'user_id' => $user->id,
                'phone' => substr((string) $user->phone, -4),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify 2FA code
     *
     * @throws ValidationException when rate limit is exceeded
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->two_factor_enabled) {
            return false;
        }

        $rateLimiterKey = '2fa|'.$user->id;

        // Check rate limit: max 5 attempts per minute
        if (RateLimiter::tooManyAttempts($rateLimiterKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimiterKey);
            Log::warning('2FA rate limit exceeded', ['user_id' => $user->id, 'retry_after' => $seconds]);
            throw ValidationException::withMessages([
                'code' => ['Too many attempts. Please wait before trying again.'],
            ]);
        }

        // Check if code has expired
        if (! $user->two_factor_code_expires_at || now()->isAfter($user->two_factor_code_expires_at)) {
            RateLimiter::hit($rateLimiterKey);
            Log::warning('2FA code expired', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        // Check if code matches using Hash::check (bcrypt constant-time comparison)
        if (! Hash::check($code, (string) $user->two_factor_code)) {
            RateLimiter::hit($rateLimiterKey);
            Log::warning('Invalid 2FA code attempt', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        // Clear the rate limiter on successful verification
        RateLimiter::clear($rateLimiterKey);

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
        if (! $user->two_factor_enabled) {
            return false;
        }

        // Recovery codes must exist and be a non-empty array
        $recoveryCodes = $user->two_factor_recovery_codes;
        if (! is_array($recoveryCodes) || count($recoveryCodes) === 0) {
            Log::warning('No recovery codes available', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        // Check if recovery code exists
        if (! in_array($code, $recoveryCodes)) {
            Log::warning('Invalid recovery code attempt', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        // Remove the used recovery code
        $recoveryCodes = array_values(array_filter($recoveryCodes, fn ($c) => $c !== $code));

        // Update user with remaining recovery codes
        $user->update([
            'two_factor_recovery_codes' => $recoveryCodes,
        ]);

        Log::info('Recovery code used successfully', [
            'user_id' => $user->id,
            'codes_remaining' => count($recoveryCodes),
        ]);

        return true;
    }

    /**
     * Generate recovery codes using cryptographically secure randomness
     */
    private function generateRecoveryCodes(int $count = 10): array
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charLen = strlen($chars);
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $chars[random_int(0, $charLen - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Get remaining recovery codes count
     */
    public function getRecoveryCodesCount(User $user): int
    {
        if (! $user->two_factor_recovery_codes) {
            return 0;
        }

        return count($user->two_factor_recovery_codes);
    }
}
