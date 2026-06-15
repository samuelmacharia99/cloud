<?php

namespace App\Mail;

use App\Models\Service;
use App\Services\ResellerBrandingResolver;
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ALERT: Container Backup Failed for '.$this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.container-backup-failed',
            with: [
                'service' => $this->service,
                'error' => $this->error,
                'siteName' => app(ResellerBrandingResolver::class)->forCustomer($this->service->user)['company_name'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
