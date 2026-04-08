<?php

namespace App\Mail;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DomainTransferInitiatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private Domain $domain)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Domain Transfer Initiated - ' . $this->domain->name . '.' . $this->domain->extension,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.domain-transfer-initiated',
            with: [
                'domain' => $this->domain,
                'fullDomain' => $this->domain->name . '.' . $this->domain->extension,
            ],
        );
    }
}
