<?php

namespace App\Services;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthEmailService
{
    public function __construct(
        private ResellerMailService $mailService,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function sendPasswordReset(User $user, string $token): bool
    {
        $resetUrl = $this->brandingResolver->customerUrl($user, 'password.reset', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);

        $mailable = new PasswordResetMail($user, $resetUrl);

        return $this->dispatchCustomerAuthMail(
            $user,
            $mailable,
            'password reset',
            'password_reset',
            [
                'customer_name' => $user->name,
                'reset_url' => $resetUrl,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $templateData
     */
    private function dispatchCustomerAuthMail(
        User $user,
        $mailable,
        string $context,
        ?string $templateEventKey = null,
        array $templateData = [],
    ): bool {
        if ($user->reseller_id !== null) {
            $reseller = $this->brandingResolver->resellerForCustomer($user);

            if ($reseller && $this->mailService->resellerSmtpEnabled($reseller)) {
                try {
                    $this->mailService->sendToCustomer(
                        $user,
                        $mailable,
                        null,
                        $templateEventKey,
                        $templateData,
                    );

                    return true;
                } catch (\Throwable $e) {
                    Log::error("Failed to send reseller-branded {$context} email", [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return false;
                }
            }

            Log::warning("{$context} email skipped — reseller SMTP not configured", [
                'user_id' => $user->id,
                'reseller_id' => $user->reseller_id,
            ]);

            return false;
        }

        if (! $this->mailService->isConfigured()) {
            Log::warning("{$context} email skipped — SMTP not configured", ['user_id' => $user->id]);

            return false;
        }

        try {
            $branding = $this->brandingResolver->forCustomer($user);
            $mailable->with(['emailBranding' => $branding]);
            Mail::to($user->email)->sendNow($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to send {$context} email", [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
