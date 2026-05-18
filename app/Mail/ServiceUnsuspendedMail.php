<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceUnsuspendedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private Service $service)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Service Restored: ' . $this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.service-unsuspended',
            with: ['service' => $this->service],
        );
    }
}
