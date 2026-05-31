<?php

namespace App\Mail;

use App\Models\ResellerDomainOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminResellerDomainPushMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ResellerDomainOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reseller domain order - '.$this->order->domain_name.$this->order->extension,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-reseller-domain-push',
            with: [
                'order' => $this->order,
                'reseller' => $this->order->reseller,
                'customer' => $this->order->customer,
            ],
        );
    }
}
