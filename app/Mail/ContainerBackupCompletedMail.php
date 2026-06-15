<?php

namespace App\Mail;

use App\Models\ContainerBackup;
use App\Models\Service;
use App\Services\ResellerBrandingResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContainerBackupCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Service $service,
        public ContainerBackup $backup,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Container Backup Completed: '.$this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.container-backup-completed',
            with: [
                'service' => $this->service,
                'backup' => $this->backup,
                'siteName' => app(ResellerBrandingResolver::class)->forCustomer($this->service->user)['company_name'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
