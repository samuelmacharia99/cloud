<?php

namespace App\Services;

use App\Mail\AccountWelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AdminAccountWelcomeService
{
    public function __construct(
        private ResellerMailService $mail,
        private ResellerBoundaryService $boundary,
    ) {}

    public function isConfigured(): bool
    {
        return $this->mail->isConfigured();
    }

    public function send(User $user, string $plainPassword, string $accountType): void
    {
        if (! in_array($accountType, ['customer', 'reseller'], true)) {
            throw new \InvalidArgumentException('Account type must be customer or reseller.');
        }

        // This mail carries platform branding and a password. A reseller's
        // customer must only ever receive that from the reseller.
        if ($accountType === 'customer') {
            $this->boundary->assertPlatformMayContact($user, 'email', 'login credentials');
        }

        if (! $this->isConfigured()) {
            throw new \RuntimeException('Platform SMTP is not configured. Set up mail settings in Admin → Settings first.');
        }

        Mail::to($user->email)->send(new AccountWelcomeMail($user, $plainPassword, $accountType));
    }
}
