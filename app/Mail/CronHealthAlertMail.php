<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CronHealthAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private array $issues)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ALERT: Cron System Health Issues Detected',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cron-health-alert',
            with: ['issues' => $this->issues],
        );
    }
}
