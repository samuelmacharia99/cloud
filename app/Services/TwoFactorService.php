<?php

namespace App\Services;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorService
{
    private const CODE_EXPIRY_MINUTES = 5;

    public function __construct(
        private AuthCodeSmsService $authCodeSms,
        private ResellerMailService $mailService,
    ) {}

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
     * Whether the user has at least one channel that can receive a login OTP.
     */
    public function canDeliverCode(User $user): bool
    {
        return $this->canSendEmail($user) || $this->authCodeSms->canSend($user);
    }

    /**
     * Send 2FA code via email and/or SMS. Succeeds if at least one channel delivers.
     *
     * @return array{email: bool, sms: bool}
     */
    public function sendCode(User $user): array
    {
        $delivery = ['email' => false, 'sms' => false];

        if (! $this->canDeliverCode($user)) {
            Log::error('Cannot send 2FA code: no email or SMS channel available', [
                'user_id' => $user->id,
                'has_email' => filled($user->email),
                'has_phone' => (bool) $user->phone,
                'mail_configured' => $this->mailService->isConfigured(),
                'sms_configured' => $this->authCodeSms->isConfiguredFor($user),
            ]);

            return $delivery;
        }

        try {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $user->update([
                'two_factor_code' => Hash::make($code),
                'two_factor_code_expires_at' => now()->addMinutes(self::CODE_EXPIRY_MINUTES),
            ]);

            $delivery['email'] = $this->sendEmailCode($user, $code);
            $delivery['sms'] = $this->sendSmsCode($user, $code);

            if (! $delivery['email'] && ! $delivery['sms']) {
                $user->update([
                    'two_factor_code' => null,
                    'two_factor_code_expires_at' => null,
                ]);

                Log::error('2FA code delivery failed on all channels', [
                    'user_id' => $user->id,
                ]);

                return $delivery;
            }

            Log::info('2FA code sent to user', [
                'user_id' => $user->id,
                'email_sent' => $delivery['email'],
                'sms_sent' => $delivery['sms'],
                'phone' => $user->phone ? substr((string) $user->phone, -4) : null,
            ]);

            return $delivery;
        } catch (\Exception $e) {
            Log::error('Failed to send 2FA code', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['email' => false, 'sms' => false];
        }
    }

    /**
     * @param  array{email: bool, sms: bool}  $delivery
     */
    public static function deliverySucceeded(array $delivery): bool
    {
        return ($delivery['email'] ?? false) || ($delivery['sms'] ?? false);
    }

    /**
     * @param  array{email: bool, sms: bool}  $delivery
     */
    public static function deliverySummary(array $delivery): string
    {
        $channels = [];
        if ($delivery['email'] ?? false) {
            $channels[] = 'email';
        }
        if ($delivery['sms'] ?? false) {
            $channels[] = 'phone (SMS)';
        }

        if ($channels === []) {
            return 'your registered contact methods';
        }

        if (count($channels) === 1) {
            return 'your '.$channels[0];
        }

        return 'your '.implode(' and ', $channels);
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

    private function canSendEmail(User $user): bool
    {
        if (! filled($user->email)) {
            return false;
        }

        if ($user->reseller_id !== null) {
            $reseller = app(ResellerBrandingResolver::class)->resellerForCustomer($user);

            return $reseller && $this->mailService->resellerSmtpEnabled($reseller);
        }

        return $this->mailService->isConfigured();
    }

    private function sendEmailCode(User $user, string $code): bool
    {
        if (! filled($user->email)) {
            return false;
        }

        $mailable = new TwoFactorCodeMail($user->name, $code, self::CODE_EXPIRY_MINUTES);

        if ($user->reseller_id !== null) {
            $reseller = app(ResellerBrandingResolver::class)->resellerForCustomer($user);

            if ($reseller && $this->mailService->resellerSmtpEnabled($reseller)) {
                try {
                    $this->mailService->sendToCustomer(
                        $user,
                        $mailable,
                        null,
                        'two_factor_code',
                        [
                            'customer_name' => $user->name,
                            'code' => $code,
                            'expiry_minutes' => (string) self::CODE_EXPIRY_MINUTES,
                        ],
                    );

                    return true;
                } catch (\Throwable $e) {
                    Log::error('Failed to send reseller-branded 2FA email', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return false;
                }
            } else {
                Log::warning('Reseller SMTP unavailable for 2FA email', [
                    'user_id' => $user->id,
                    'reseller_id' => $user->reseller_id,
                ]);
            }

            return false;
        }

        if (! $this->mailService->isConfigured()) {
            Log::warning('2FA email skipped — SMTP not configured', ['user_id' => $user->id]);

            return false;
        }

        try {
            $mailable->with([
                'emailBranding' => app(ResellerBrandingResolver::class)->forCustomer($user),
            ]);
            Mail::to($user->email)->sendNow($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send 2FA email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendSmsCode(User $user, string $code): bool
    {
        if (! $this->authCodeSms->canSend($user)) {
            return false;
        }

        $siteName = $this->authCodeSms->siteNameFor($user);
        $message = "Your {$siteName} login code is: {$code}. Valid for ".self::CODE_EXPIRY_MINUTES.' minutes. Do not share this code.';
        $smsResult = $this->authCodeSms->send($user, $message);

        if ($smsResult['success'] ?? false) {
            return true;
        }

        Log::error('2FA SMS delivery failed', [
            'user_id' => $user->id,
            'phone' => $user->phone ? substr((string) $user->phone, -4) : null,
            'sms_message' => $smsResult['message'] ?? 'Unknown error',
        ]);

        return false;
    }
}
