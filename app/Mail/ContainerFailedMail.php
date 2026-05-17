<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContainerFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Service $service,
        public string $reason,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ALERT: Container Failed - ' . $this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.container-failed',
            with: [
                'service' => $this->service,
                'reason' => $this->reason,
                'siteName' => \App\Models\Setting::getValue('site_name', 'Talksasa Cloud'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
