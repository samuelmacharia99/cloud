<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

class EmailVerificationService
{
    public function __construct(
        private ResellerMailService $mailService,
        private AuthCodeSmsService $authCodeSms,
    ) {}

    /**
     * Create a fresh OTP and deliver via email and/or SMS (at least one must succeed).
     *
     * @return array{email: bool, sms: bool}
     *
     * @throws \RuntimeException When rate limited or no delivery channel succeeds.
     */
    public function sendVerificationCode(User $user, int $expiryMinutes = 30): array
    {
        if ($user->hasVerifiedEmail()) {
            throw new \RuntimeException('Email is already verified.');
        }

        if (SecurityService::isEmailVerificationRateLimited($user->email)) {
            throw new \RuntimeException('Too many verification codes sent. Please wait before trying again.');
        }

        EmailVerificationCode::where('user_id', $user->id)->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        $siteName = $this->authCodeSms->siteNameFor($user);
        $smsMessage = "Your {$siteName} verification code is: {$code}. Valid for {$expiryMinutes} minutes. Do not share this code.";

        $emailSent = $this->sendEmailCode($user, $code);
        $smsSent = $this->sendSmsCode($user, $smsMessage);

        if (! $emailSent && ! $smsSent) {
            EmailVerificationCode::where('user_id', $user->id)->delete();

            throw new \RuntimeException(
                'Could not deliver a verification code. Configure email or SMS in admin settings, or add a phone number to your profile.'
            );
        }

        SecurityService::recordEmailVerificationAttempt($user->email);

        Log::info('Verification code dispatched', [
            'user_id' => $user->id,
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent,
        ]);

        return [
            'email' => $emailSent,
            'sms' => $smsSent,
        ];
    }

    /**
     * Human-readable summary for flash messages.
     *
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

    private function sendEmailCode(User $user, string $code): bool
    {
        $mailable = new VerificationCodeMail($user->name, $code);

        if ($user->reseller_id !== null) {
            $reseller = app(ResellerBrandingResolver::class)->resellerForCustomer($user);

            if ($reseller && $this->mailService->resellerSmtpEnabled($reseller)) {
                try {
                    $this->mailService->sendToCustomer($user, $mailable);

                    return true;
                } catch (\Throwable $e) {
                    Log::error('Failed to send reseller-branded verification email', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning('Reseller SMTP unavailable for verification email — trying platform SMTP', [
                    'user_id' => $user->id,
                    'reseller_id' => $user->reseller_id,
                ]);
            }
        }

        if (! $this->mailService->isConfigured()) {
            Log::warning('Verification email skipped — SMTP not configured', ['user_id' => $user->id]);

            return false;
        }

        try {
            View::share('emailBranding', app(ResellerBrandingResolver::class)->forCustomer($user));
            Mail::to($user->email)->sendNow($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification code', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendSmsCode(User $user, string $message): bool
    {
        if (! $this->authCodeSms->canSend($user)) {
            return false;
        }

        $result = $this->authCodeSms->send($user, $message);

        if (! ($result['success'] ?? false)) {
            Log::warning('Verification SMS delivery failed', [
                'user_id' => $user->id,
                'channel' => $result['channel'] ?? null,
                'message' => $result['message'] ?? 'unknown',
            ]);

            return false;
        }

        return true;
    }
}
