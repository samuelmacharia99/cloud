<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerAccountTransferredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.email_company_name().' account has been updated',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-account-transferred',
            with: [
                'user' => $this->user,
                'portalUrl' => $this->portalUrl,
            ],
        );
    }
}
