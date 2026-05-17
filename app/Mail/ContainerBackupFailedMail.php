<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContainerBackupFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Service $service,
        public string $error,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ALERT: Container Backup Failed for ' . $this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.container-backup-failed',
            with: [
                'service' => $this->service,
                'error' => $this->error,
                'siteName' => \App\Models\Setting::getValue('site_name', 'Talksasa Cloud'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
