<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
        public string $accountType,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.email_company_name().' — Your Account Details',
        );
    }

    public function content(): Content
    {
        $branding = email_branding();

        return new Content(
            view: 'emails.account-welcome',
            with: [
                'user' => $this->user,
                'plainPassword' => $this->plainPassword,
                'accountType' => $this->accountType,
                'loginUrl' => $branding['portal_url'] ?: route('login'),
            ],
        );
    }
}
