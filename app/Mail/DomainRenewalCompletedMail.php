<?php

namespace App\Mail;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DomainRenewalCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public DomainRenewalOrder $renewalOrder,
        public Domain $domain,
        public User $recipient,
        public int $years,
        public Carbon $newExpiry,
        public ?string $endCustomerName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Domain renewed — '.$this->domain->fqdn(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.domain-renewal-completed',
            with: [
                'renewalOrder' => $this->renewalOrder,
                'domain' => $this->domain,
                'recipient' => $this->recipient,
                'years' => $this->years,
                'newExpiry' => $this->newExpiry,
                'endCustomerName' => $this->endCustomerName,
                'fqdn' => $this->domain->fqdn(),
            ],
        );
    }
}
