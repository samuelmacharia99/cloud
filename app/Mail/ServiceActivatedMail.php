<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceActivatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private Service $service)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Service "' . $this->service->name . '" is Now Active',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.service-activated',
            with: ['service' => $this->service],
        );
    }
}
