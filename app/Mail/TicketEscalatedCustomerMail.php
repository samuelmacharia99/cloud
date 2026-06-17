<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketEscalatedCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Support ticket #'.$this->ticket->id.' escalated for priority review',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-escalated-customer',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
