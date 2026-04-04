<?php

namespace App\Mail;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DomainExpiryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private Domain $domain,
        private int $daysUntilExpiry,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Domain Expiry Notice: ' . $this->domain->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.domain-expiry',
            with: [
                'domain' => $this->domain,
                'daysUntilExpiry' => $this->daysUntilExpiry,
            ],
        );
    }
}
