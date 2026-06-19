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

class HostingPackageUsageWarningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, array<string, mixed>>  $metricsAtRisk
     */
    public function __construct(
        private Service $service,
        private array $metricsAtRisk,
        private ?Product $recommendedUpgrade = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Action needed: '.$this->service->name.' is nearing its plan limits',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.hosting-package-usage-warning',
            with: [
                'service' => $this->service,
                'metricsAtRisk' => $this->metricsAtRisk,
                'recommendedUpgrade' => $this->recommendedUpgrade,
                'upgradeUrl' => route('customer.services.upgrade', $this->service),
            ],
        );
    }
}
