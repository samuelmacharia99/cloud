<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManualPaymentRejected extends Notification implements ShouldQueue
{
    use Queueable;

    protected $payment;

    /**
     * Create a new notification instance.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $notes = json_decode($this->payment->notes, true) ?? [];

        return (new MailMessage)
            ->subject('Manual Payment Submission — Rejected')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Unfortunately, your manual payment submission for invoice **' . $this->payment->invoice->invoice_number . '** has been rejected.')
            ->line('**Rejection Reason:** ' . ($notes['rejection_reason'] ?? 'Not specified'))
            ->line('Amount: Ksh ' . number_format($this->payment->amount, 0))
            ->line('Please contact our support team if you have any questions, or try submitting your payment again with correct details.')
            ->action('View Invoice', route('customer.invoices.show', $this->payment->invoice))
            ->line('Thank you for your patience.');
    }
}
