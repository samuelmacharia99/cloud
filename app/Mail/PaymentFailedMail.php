<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        $invoice = $this->payment->invoice;

        return new Envelope(
            subject: 'Payment failed — Invoice '.($invoice->invoice_number ?? '#'.$invoice->id),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-failed',
            with: [
                'payment' => $this->payment,
                'invoice' => $this->payment->invoice,
                'reason' => $this->reason,
            ],
        );
    }
}
