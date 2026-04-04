<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Domain;
use App\Models\Service;
use App\Models\Payment;
use App\Mail\InvoiceGeneratedMail;
use App\Mail\InvoiceReminderMail;
use App\Mail\InvoiceOverdueMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\ServiceActivatedMail;
use App\Mail\ServiceSuspendedMail;
use App\Mail\ServiceTerminatedMail;
use App\Mail\DomainExpiryMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

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

    private function logEmail(string $to, string $subject, string $status, ?string $error = null): void
    {
        try {
            \App\Models\Email::create([
                'recipient' => $to,
                'subject' => $subject,
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
}
