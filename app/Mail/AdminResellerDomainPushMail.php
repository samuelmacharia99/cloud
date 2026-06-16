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
        $prefix = $this->order->isPlatformOrder() ? 'Platform domain order' : 'Reseller domain order';

        return new Envelope(
            subject: $prefix.' - '.$this->order->fullDomainName(),
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
