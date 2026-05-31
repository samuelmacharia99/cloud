<?php

namespace App\Mail;

use App\Models\ResellerDomainOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResellerDomainOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ResellerDomainOrder $order,
        public string $variant = 'queued',
    ) {}

    public function envelope(): Envelope
    {
        $domain = $this->order->domain_name.$this->order->extension;

        return new Envelope(
            subject: match ($this->variant) {
                'pushed' => "Domain pushed to admin - {$domain}",
                'completed' => "Domain registered - {$domain}",
                'failed' => "Domain registration failed - {$domain}",
                default => "Domain order queued - {$domain}",
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reseller-domain-order',
            with: [
                'order' => $this->order,
                'variant' => $this->variant,
                'customer' => $this->order->customer,
                'reseller' => $this->order->reseller,
            ],
        );
    }
}
