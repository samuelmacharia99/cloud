<?php

namespace App\Services;

use App\Mail\AccountWelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class AdminAccountWelcomeService
{
    public function __construct(
        private ResellerMailService $mail,
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

        if (! $this->isConfigured()) {
            throw new \RuntimeException('Platform SMTP is not configured. Set up mail settings in Admin → Settings first.');
        }

        Mail::to($user->email)->send(new AccountWelcomeMail($user, $plainPassword, $accountType));
    }
}
