<?php

namespace App\Mail;

use App\Models\Setting;
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
        $company = Setting::getValue('company_name', config('app.name', 'Talksasa Cloud'));

        return new Envelope(
            subject: "Welcome to {$company} — Your Account Details",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-welcome',
            with: [
                'user' => $this->user,
                'plainPassword' => $this->plainPassword,
                'accountType' => $this->accountType,
                'loginUrl' => route('login'),
            ],
        );
    }
}
