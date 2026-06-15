<?php

namespace App\Mail;

use App\Models\Service;
use App\Services\ResellerBrandingResolver;
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ALERT: Container Failed - '.$this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.container-failed',
            with: [
                'service' => $this->service,
                'reason' => $this->reason,
                'siteName' => app(ResellerBrandingResolver::class)->forCustomer($this->service->user)['company_name'],
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
