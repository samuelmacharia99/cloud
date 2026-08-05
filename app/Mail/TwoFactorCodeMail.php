<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TwoFactorCodeMail extends Mailable
{
    public function __construct(
        private string $name,
        private string $code,
        private int $expiryMinutes = 5,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your login verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.two-factor-code',
            with: [
                'name' => $this->name,
                'code' => $this->code,
                'expiryMinutes' => $this->expiryMinutes,
            ],
        );
    }
}
