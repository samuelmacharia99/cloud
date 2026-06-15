<?php

namespace App\Mail;

use App\Models\Service;
use App\Services\ResellerBrandingResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContainerAutoRestartedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Service $service,
        public int $attemptCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Container Auto-Restarted: '.$this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.container-auto-restarted',
            with: [
                'service' => $this->service,
                'attemptCount' => $this->attemptCount,
                'siteName' => app(ResellerBrandingResolver::class)->forCustomer($this->service->user)['company_name'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
