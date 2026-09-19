<?php

namespace App\Mail;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;

/**
 * Sent once, when the nameservers finally resolve and the zone starts serving.
 *
 * Delivery is not decided here: EmailDeliveryService routes a customer who
 * belongs to a reseller through that reseller's own SMTP and branding, so this
 * arrives from whoever actually sold the domain.
 */
class DomainDnsLiveMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private Domain $domain) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'DNS is live for '.$this->domain->fqdn(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.domain-dns-live',
            with: [
                'domain' => $this->domain,
                'dnsUrl' => Route::has('customer.domains.dns.index')
                    ? customer_portal_route($this->domain->user, 'customer.domains.dns.index', $this->domain)
                    : url('/my/domains/'.$this->domain->id.'/dns'),
            ],
        );
    }
}
