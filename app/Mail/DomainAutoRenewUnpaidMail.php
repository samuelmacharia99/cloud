<?php

namespace App\Mail;

use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DomainAutoRenewUnpaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private Invoice $invoice,
        private Domain $domain,
        private float $amountDue,
        private string $prepaidLabel,
        private string $topupUrl,
        private string $invoiceUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Auto-renew could not pay '.$this->domain->fqdn(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.domain-auto-renew-unpaid',
            with: [
                'invoice' => $this->invoice,
                'domain' => $this->domain,
                'amountDue' => $this->amountDue,
                'prepaidLabel' => $this->prepaidLabel,
                'topupUrl' => $this->topupUrl,
                'invoiceUrl' => $this->invoiceUrl,
            ],
        );
    }
}
