<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Domain;
use App\Models\Service;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Mail\InvoiceGeneratedMail;
use App\Mail\InvoiceReminderMail;
use App\Mail\InvoiceOverdueMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\ServiceActivatedMail;
use App\Mail\ServiceSuspendedMail;
use App\Mail\ServiceTerminatedMail;
use App\Mail\DomainExpiryMail;
use App\Mail\TicketCreatedMail;
use App\Mail\TicketRepliedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private SmsService $smsService)
    {
    }

    private function settingEnabled(string $key): bool
    {
        return \App\Models\Setting::getValue($key, 'true') === 'true';
    }

    private function smtpConfigured(): bool
    {
        return !empty(\App\Models\Setting::getValue('smtp_host', ''));
    }

    private function logEmail(string $to, string $subject, string $status, ?string $error = null, ?string $body = null): void
    {
        try {
            \App\Models\Email::create([
                'recipient' => $to,
                'subject' => $subject,
                'body' => $body ?? '',
                'status' => $status,
                'response' => $error,
                'sent_by' => null,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log email', ['error' => $e->getMessage()]);
        }
    }

    public function notifyInvoiceGenerated(Invoice $invoice): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_invoice_generated')) {
            return;
        }

        try {
            Mail::to($invoice->user->email)->send(new InvoiceGeneratedMail($invoice));
            $this->logEmail($invoice->user->email, 'Invoice ' . $invoice->invoice_number . ' Generated', 'sent');
        } catch (\Exception $e) {
            $this->logEmail($invoice->user->email, 'Invoice ' . $invoice->invoice_number . ' Generated', 'failed', $e->getMessage());
            Log::error('Failed to send invoice generated notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $invoice->user->phone && $this->settingEnabled('notify_invoice_generated')) {
            try {
                $this->smsService->send($invoice->user->phone, 'Invoice ' . $invoice->invoice_number . ' has been generated. Amount due: Ksh ' . number_format($invoice->total, 0) . '. Pay online at: ' . url('/'));
            } catch (\Exception $e) {
                Log::error('Failed to send invoice generated SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyInvoiceReminder(Invoice $invoice, int $daysBefore): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_invoice_reminder')) {
            return;
        }

        try {
            Mail::to($invoice->user->email)->send(new InvoiceReminderMail($invoice, $daysBefore));
            $this->logEmail($invoice->user->email, 'Payment Reminder: Invoice ' . $invoice->invoice_number, 'sent');
        } catch (\Exception $e) {
            $this->logEmail($invoice->user->email, 'Payment Reminder: Invoice ' . $invoice->invoice_number, 'failed', $e->getMessage());
            Log::error('Failed to send invoice reminder notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $invoice->user->phone && $this->settingEnabled('notify_invoice_reminder')) {
            try {
                $message = $daysBefore <= 0 ? 'URGENT: Invoice ' . $invoice->invoice_number . ' is due today!' : 'Reminder: Invoice ' . $invoice->invoice_number . ' is due in ' . $daysBefore . ' days.';
                $this->smsService->send($invoice->user->phone, $message);
            } catch (\Exception $e) {
                Log::error('Failed to send invoice reminder SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyInvoiceOverdue(Invoice $invoice): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_invoice_overdue')) {
            return;
        }

        try {
            Mail::to($invoice->user->email)->send(new InvoiceOverdueMail($invoice));
            $this->logEmail($invoice->user->email, 'URGENT: Invoice ' . $invoice->invoice_number . ' is Overdue', 'sent');
        } catch (\Exception $e) {
            $this->logEmail($invoice->user->email, 'URGENT: Invoice ' . $invoice->invoice_number . ' is Overdue', 'failed', $e->getMessage());
            Log::error('Failed to send invoice overdue notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $invoice->user->phone && $this->settingEnabled('notify_invoice_overdue')) {
            try {
                $this->smsService->send($invoice->user->phone, 'URGENT: Invoice ' . $invoice->invoice_number . ' is now overdue. Immediate payment required to avoid service suspension.');
            } catch (\Exception $e) {
                Log::error('Failed to send invoice overdue SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyPaymentReceived(Payment $payment): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_payment')) {
            return;
        }

        try {
            Mail::to($payment->invoice->user->email)->send(new PaymentReceivedMail($payment));
            $this->logEmail($payment->invoice->user->email, 'Payment Received for Invoice ' . $payment->invoice->invoice_number, 'sent');
        } catch (\Exception $e) {
            $this->logEmail($payment->invoice->user->email, 'Payment Received for Invoice ' . $payment->invoice->invoice_number, 'failed', $e->getMessage());
            Log::error('Failed to send payment received notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $payment->invoice->user->phone && $this->settingEnabled('notify_payment')) {
            try {
                $this->smsService->send($payment->invoice->user->phone, 'Payment of Ksh ' . number_format($payment->amount, 0) . ' received for invoice ' . $payment->invoice->invoice_number . '. Thank you!');
            } catch (\Exception $e) {
                Log::error('Failed to send payment received SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyServiceActivated(Service $service): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_service_activated')) {
            return;
        }

        try {
            Mail::to($service->user->email)->send(new ServiceActivatedMail($service));
            $this->logEmail($service->user->email, 'Your Service "' . $service->name . '" is Now Active', 'sent');
        } catch (\Exception $e) {
            $this->logEmail($service->user->email, 'Your Service "' . $service->name . '" is Now Active', 'failed', $e->getMessage());
            Log::error('Failed to send service activated notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $service->user->phone && $this->settingEnabled('notify_service_activated')) {
            try {
                $this->smsService->send($service->user->phone, 'Your service "' . $service->name . '" is now active and ready to use. Log in to your dashboard for details.');
            } catch (\Exception $e) {
                Log::error('Failed to send service activated SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyServiceSuspended(Service $service): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_service_suspend')) {
            return;
        }

        try {
            Mail::to($service->user->email)->send(new ServiceSuspendedMail($service));
            $this->logEmail($service->user->email, 'Service Suspension Notice: ' . $service->name, 'sent');
        } catch (\Exception $e) {
            $this->logEmail($service->user->email, 'Service Suspension Notice: ' . $service->name, 'failed', $e->getMessage());
            Log::error('Failed to send service suspended notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $service->user->phone && $this->settingEnabled('notify_service_suspend')) {
            try {
                $this->smsService->send($service->user->phone, 'Your service "' . $service->name . '" has been suspended due to overdue payment. Pay now to restore service.');
            } catch (\Exception $e) {
                Log::error('Failed to send service suspended SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyServiceTerminated(Service $service): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_service_terminated')) {
            return;
        }

        try {
            Mail::to($service->user->email)->send(new ServiceTerminatedMail($service));
            $this->logEmail($service->user->email, 'Service Termination Notice: ' . $service->name, 'sent');
        } catch (\Exception $e) {
            $this->logEmail($service->user->email, 'Service Termination Notice: ' . $service->name, 'failed', $e->getMessage());
            Log::error('Failed to send service terminated notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $service->user->phone && $this->settingEnabled('notify_service_terminated')) {
            try {
                $this->smsService->send($service->user->phone, 'Your service "' . $service->name . '" has been terminated. Contact support if you wish to restore it.');
            } catch (\Exception $e) {
                Log::error('Failed to send service terminated SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyDomainExpiry(Domain $domain, int $daysUntilExpiry): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_domain_expiry')) {
            return;
        }

        try {
            Mail::to($domain->user->email)->send(new DomainExpiryMail($domain, $daysUntilExpiry));
            $this->logEmail($domain->user->email, 'Domain Expiry Notice: ' . $domain->name, 'sent');
        } catch (\Exception $e) {
            $this->logEmail($domain->user->email, 'Domain Expiry Notice: ' . $domain->name, 'failed', $e->getMessage());
            Log::error('Failed to send domain expiry notification', ['error' => $e->getMessage()]);
        }

        if ($this->smsService->isConfigured() && $domain->user->phone && $this->settingEnabled('notify_domain_expiry')) {
            try {
                $message = $daysUntilExpiry <= 0 ? 'URGENT: Your domain ' . $domain->name . ' has expired!' : 'Your domain ' . $domain->name . ' expires in ' . $daysUntilExpiry . ' days. Renew now!';
                $this->smsService->send($domain->user->phone, $message);
            } catch (\Exception $e) {
                Log::error('Failed to send domain expiry SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Notify admin and customer about a new order placement.
     * Admin receives SMS to all notification phones with order summary.
     * Customer receives SMS with order confirmation and payment-specific instructions.
     */
    public function notifyNewOrder(\App\Models\Order $order, Invoice $invoice, string $paymentMethod = 'unknown'): void
    {
        // Notify all admins
        if ($this->smsService->isConfigured() && $this->settingEnabled('notify_new_order')) {
            try {
                $adminUsers = \App\Models\User::where('is_admin', true)
                    ->whereNotNull('notification_phones')
                    ->get();

                Log::info('Notifying admins about new order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'admin_count' => $adminUsers->count(),
                ]);

                foreach ($adminUsers as $admin) {
                    if (!empty($admin->notification_phones) && is_array($admin->notification_phones)) {
                        try {
                            $adminMessage = 'New order #' . $order->order_number . ' from ' . $order->user->name . '. Payment: ' . ucfirst($paymentMethod) . '. Amount: KES ' . number_format($invoice->total, 0) . '.';

                            $this->smsService->send($admin->notification_phones, $adminMessage);

                            Log::info('Admin order notification SMS sent', [
                                'order_id' => $order->id,
                                'admin_id' => $admin->id,
                                'admin_name' => $admin->name,
                                'phone_count' => count($admin->notification_phones),
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send admin order notification SMS', [
                                'order_id' => $order->id,
                                'admin_id' => $admin->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to notify admins about new order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify customer
        if ($this->smsService->isConfigured() && $order->user->phone) {
            try {
                $customerMessage = match ($paymentMethod) {
                    'manual' => 'Your order #' . $order->order_number . ' (KES ' . number_format($invoice->total, 0) . ') has been placed. Please complete your bank transfer. An admin will activate your service after payment verification.',
                    default => 'Your order #' . $order->order_number . ' (KES ' . number_format($invoice->total, 0) . ') has been placed and payment is being processed. Service credentials will be emailed once provisioned.',
                };

                $this->smsService->send($order->user->phone, $customerMessage);

                Log::info('Customer order notification SMS sent', [
                    'order_id' => $order->id,
                    'customer_id' => $order->user->id,
                    'payment_method' => $paymentMethod,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send customer order notification SMS', [
                    'order_id' => $order->id,
                    'customer_id' => $order->user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify about a new support ticket creation
     * - Sends email to ticket owner
     * - For reseller's customer tickets, notifies the reseller
     * - For non-reseller customers, notifies admin
     */
    public function notifyTicketCreated(Ticket $ticket): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_ticket')) {
            return;
        }

        try {
            // Always send to ticket owner (the customer who created it)
            Mail::to($ticket->user->email)->send(new TicketCreatedMail($ticket));
            $this->logEmail($ticket->user->email, 'Support Ticket #' . $ticket->id . ' Created', 'sent', null, $ticket->description);

            Log::info('Ticket creation notification sent to customer', [
                'ticket_id' => $ticket->id,
                'customer_id' => $ticket->user->id,
            ]);
        } catch (\Exception $e) {
            $this->logEmail($ticket->user->email, 'Support Ticket #' . $ticket->id . ' Created', 'failed', $e->getMessage(), $ticket->description);
            Log::error('Failed to send ticket creation notification', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Notify admin about new ticket via SMS if configured
        if ($this->smsService->isConfigured() && $this->settingEnabled('notify_ticket')) {
            try {
                $adminUsers = \App\Models\User::where('is_admin', true)
                    ->whereNotNull('notification_phones')
                    ->get();

                foreach ($adminUsers as $admin) {
                    if (!empty($admin->notification_phones) && is_array($admin->notification_phones)) {
                        try {
                            $message = 'New support ticket #' . $ticket->id . ' from ' . $ticket->user->name . '. Priority: ' . ucfirst($ticket->priority) . '. Title: ' . Str::limit($ticket->title, 50);
                            $this->smsService->send($admin->notification_phones, $message);

                            Log::info('Ticket creation SMS sent to admin', [
                                'ticket_id' => $ticket->id,
                                'admin_id' => $admin->id,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send ticket creation SMS to admin', [
                                'ticket_id' => $ticket->id,
                                'admin_id' => $admin->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to notify admins about ticket creation', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify about a new reply to a support ticket
     * - If staff replies, send to ticket owner
     * - If customer replies, send to assigned staff or admin
     */
    public function notifyTicketReplied(Ticket $ticket, TicketReply $reply): void
    {
        if (!$this->smtpConfigured() || !$this->settingEnabled('notify_ticket')) {
            return;
        }

        try {
            if ($reply->is_staff_reply) {
                // Staff replied - notify ticket owner
                Mail::to($ticket->user->email)->send(new TicketRepliedMail($ticket, $reply));
                $this->logEmail($ticket->user->email, 'Re: Support Ticket #' . $ticket->id, 'sent', null, $reply->message);

                Log::info('Ticket reply notification sent to customer', [
                    'ticket_id' => $ticket->id,
                    'reply_id' => $reply->id,
                ]);

                // Also notify customer via SMS if configured
                if ($this->smsService->isConfigured() && $ticket->user->phone) {
                    try {
                        $message = 'New reply to your support ticket #' . $ticket->id . '. Status: ' . ucfirst(str_replace('_', ' ', $ticket->status)) . '. Check your email or log in to view.';
                        $this->smsService->send($ticket->user->phone, $message);
                    } catch (\Exception $e) {
                        Log::error('Failed to send ticket reply SMS to customer', [
                            'ticket_id' => $ticket->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                // Customer replied - notify assigned staff or admins
                $notifyUser = $ticket->assignee ?? \App\Models\User::where('is_admin', true)->first();

                if ($notifyUser) {
                    Mail::to($notifyUser->email)->send(new TicketRepliedMail($ticket, $reply));
                    $this->logEmail($notifyUser->email, 'Customer Reply: Support Ticket #' . $ticket->id, 'sent', null, $reply->message);

                    Log::info('Ticket reply notification sent to staff', [
                        'ticket_id' => $ticket->id,
                        'reply_id' => $reply->id,
                        'staff_id' => $notifyUser->id,
                    ]);

                    // Notify via SMS if staff has notification phones
                    if ($this->smsService->isConfigured() && $notifyUser->notification_phones && is_array($notifyUser->notification_phones)) {
                        try {
                            $message = 'Customer reply to ticket #' . $ticket->id . '. Title: ' . Str::limit($ticket->title, 50);
                            $this->smsService->send($notifyUser->notification_phones, $message);
                        } catch (\Exception $e) {
                            Log::error('Failed to send ticket reply SMS to staff', [
                                'ticket_id' => $ticket->id,
                                'staff_id' => $notifyUser->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send ticket reply notification', [
                'ticket_id' => $ticket->id,
                'reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
