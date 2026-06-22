<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResellerCustomerAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{services: int, domains: int, cancelled_invoices: int, from_label: string}  $summary
     */
    public function __construct(
        public User $reseller,
        public User $customer,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New customer assigned: '.$this->customer->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reseller-customer-assigned',
            with: [
                'reseller' => $this->reseller,
                'customer' => $this->customer,
                'summary' => $this->summary,
            ],
        );
    }
}
