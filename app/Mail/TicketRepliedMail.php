<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketRepliedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketReply $reply
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Re: Support Ticket #' . $this->ticket->id . ' - ' . $this->ticket->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-replied',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
