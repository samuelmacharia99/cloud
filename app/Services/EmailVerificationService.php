<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailVerificationService
{
    public function __construct(
        private ResellerMailService $mailService,
    ) {}

    /**
     * Create a fresh OTP and send it synchronously (never queued).
     *
     * @throws \RuntimeException When SMTP is not configured or rate limited.
     */
    public function sendVerificationCode(User $user, int $expiryMinutes = 30): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new \RuntimeException('Email is already verified.');
        }

        if (! $this->mailService->isConfigured()) {
            throw new \RuntimeException('Email is not configured on the server. Please contact support.');
        }

        if (SecurityService::isEmailVerificationRateLimited($user->email)) {
            throw new \RuntimeException('Too many verification emails sent. Please wait before trying again.');
        }

        EmailVerificationCode::where('user_id', $user->id)->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        try {
            Mail::to($user->email)->send(new VerificationCodeMail($user->name, $code));
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification code', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Could not send verification email. Please try again or contact support.');
        }

        SecurityService::recordEmailVerificationAttempt($user->email);
    }
}
