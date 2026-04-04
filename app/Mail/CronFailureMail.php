<?php

namespace App\Mail;

use App\Models\CronJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CronFailureMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private CronJob $cronJob)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ALERT: Cron Job Failed — ' . $this->cronJob->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cron-failure',
            with: [
                'job' => $this->cronJob,
                'latestLog' => $this->cronJob->latestLog,
            ],
        );
    }
}
