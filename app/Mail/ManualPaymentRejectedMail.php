<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ManualPaymentRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public string $rejectionReason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Manual Payment Submission — Rejected',
        );
    }

    public function content(): Content
    {
        $this->payment->loadMissing('invoice.user');

        return new Content(
            view: 'emails.manual-payment-rejected',
            with: [
                'payment' => $this->payment,
                'rejectionReason' => $this->rejectionReason,
                'invoiceUrl' => route('customer.invoices.show', $this->payment->invoice),
            ],
        );
    }
}
