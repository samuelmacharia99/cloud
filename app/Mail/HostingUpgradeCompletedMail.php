<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HostingUpgradeCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private Service $service,
        private Product $previousProduct,
        private Product $newProduct,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Plan upgraded: '.$this->service->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hosting-upgrade-completed',
            with: [
                'service' => $this->service,
                'previousProduct' => $this->previousProduct,
                'newProduct' => $this->newProduct,
                'serviceUrl' => route('customer.services.show', $this->service),
            ],
        );
    }
}
