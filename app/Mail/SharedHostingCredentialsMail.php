<?php

namespace App\Mail;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SharedHostingCredentialsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Service $service) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->service->product->name.' Control Panel Login Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shared-hosting-credentials',
            with: [
                'service' => $this->service,
                'credentials' => $this->service->getHostingCredentials(),
            ],
        );
    }
}
