<?php

namespace App\Services;

use App\Mail\AccountWelcomeMail;
use App\Models\User;

class ResellerCustomerWelcomeService
{
    public function __construct(
        private ResellerMailService $mail,
    ) {}

    public function canSend(User $reseller): bool
    {
        return $this->mail->resellerSmtpEnabled($reseller);
    }

    public function send(User $reseller, User $customer, string $plainPassword): void
    {
        if ($customer->reseller_id !== $reseller->id) {
            throw new \InvalidArgumentException('Customer does not belong to this reseller.');
        }

        if (! $this->canSend($reseller)) {
            throw new \RuntimeException(
                'Reseller SMTP is not configured. Enable SMTP under Settings → Email before sending welcome emails.'
            );
        }

        $this->mail->sendToCustomer(
            $customer,
            new AccountWelcomeMail($customer, $plainPassword, 'customer')
        );
    }
}
