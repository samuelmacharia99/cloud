<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceProvisionFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Service $service,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Service Setup Failed — '.$this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.service-provision-failed',
            with: [
                'service' => $this->service,
                'reason' => $this->reason,
            ],
        );
    }
}
