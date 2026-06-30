<?php

namespace App\Services;

use App\Enums\NotificationEvent;
use App\Enums\PaymentStatus;
use App\Mail\AdminManualPaymentMail;
use App\Mail\AdminResellerDomainPushMail;
use App\Mail\ContainerAutoRestartedMail;
use App\Mail\ContainerBackupCompletedMail;
use App\Mail\ContainerBackupFailedMail;
use App\Mail\ContainerFailedMail;
use App\Mail\DomainExpiryMail;
use App\Mail\DomainRenewalCompletedMail;
use App\Mail\DomainTransferInitiatedMail;
use App\Mail\GenericNotificationMail;
use App\Mail\HostingPackageUsageWarningMail;
use App\Mail\HostingUpgradeCompletedMail;
use App\Mail\InvoiceGeneratedMail;
use App\Mail\InvoiceOverdueMail;
use App\Mail\InvoiceReminderMail;
use App\Mail\ManualPaymentRejectedMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\PasswordChangedMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\ServerCredentialsMail;
use App\Mail\ServiceActivatedMail;
use App\Mail\ServiceProvisionFailedMail;
use App\Mail\ServiceSuspendedMail;
use App\Mail\ServiceTerminatedMail;
use App\Mail\ServiceUnsuspendedMail;
use App\Mail\SharedHostingCredentialsMail;
use App\Models\ContainerBackup;
use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\SmsTemplate;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(
        private SmsService $smsService,
        private EmailDeliveryService $emailDelivery,
        private NotificationPreferenceService $preferences,
        private ResellerBrandingResolver $brandingResolver,
        private TalksasaSmsService $talksasaSms,
        private AuthCodeSmsService $authCodeSms,
    ) {}

    private function telegram(): TelegramMonitorBridge
    {
        return app(TelegramMonitorBridge::class);
    }

    private function siteNameFor(User $customer): string
    {
        return $this->brandingResolver->forCustomer($customer)['company_name'];
    }

    private function sendCustomerEmail(User $customer, $mailable, string $subject, NotificationEvent $event, ?string $logBody = null): void
    {
        try {
            $this->emailDelivery->sendCustomerMailable($customer, $mailable, $subject, $event, $logBody);
        } catch (\Exception $e) {
            Log::error('Failed to send customer email', ['event' => $event->value, 'error' => $e->getMessage()]);
        }
    }

    private function sendPlatformEmail(string $email, $mailable, string $subject, NotificationEvent $event, ?User $user = null, ?string $logBody = null): void
    {
        try {
            $this->emailDelivery->sendPlatformMailable($email, $mailable, $subject, $event, $user, $logBody);
        } catch (\Exception $e) {
            Log::error('Failed to send platform email', ['event' => $event->value, 'email' => $email, 'error' => $e->getMessage()]);
        }
    }

    private function sendCustomerSms(User $customer, string $message, NotificationEvent $event): void
    {
        if (! $customer->phone || ! $this->preferences->isSmsEnabledForUser($customer, $event)) {
            return;
        }

        // Ownership boundary:
        // - Reseller-owned customers are messaged only through their reseller SMS config.
        // - Admin-owned customers are messaged through admin/global SMS config.
        if ($customer->reseller_id !== null) {
            $reseller = $this->brandingResolver->resellerForCustomer($customer);
            if ($reseller) {
                $sms = $reseller->settings['sms'] ?? [];
                if (! empty($sms['enabled']) && ! empty($sms['api_key'])) {
                    $this->talksasaSms->sendSms($reseller, $customer->phone, $message);
                } else {
                    Log::warning('Skipped SMS to reseller-owned customer due to missing reseller SMS config', [
                        'customer_id' => $customer->id,
                        'reseller_id' => $reseller->id,
                        'event' => $event->value,
                    ]);
                }
            }

            return;
        }

        if ($this->smsService->isConfigured()) {
            $this->smsService->send($customer->phone, $message);
        }
    }

    private function renderTemplate(string $eventKey, array $data, string $fallback): string
    {
        try {
            $template = SmsTemplate::where('event_key', $eventKey)->first();

            return $template ? $template->render($data) : $fallback;
        } catch (\Exception $e) {
            Log::warning('Failed to load SMS template for event: '.$eventKey, ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    public function sendAdminSmsAlert(NotificationEvent $event, string $message): void
    {
        if (! $this->smsService->isConfigured() || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        try {
            foreach (User::where('is_admin', true)->whereNotNull('notification_phones')->get() as $admin) {
                if (empty($admin->notification_phones) || ! is_array($admin->notification_phones)) {
                    continue;
                }

                try {
                    $this->smsService->send($admin->notification_phones, $message);
                } catch (\Exception $e) {
                    Log::error('Failed to send admin SMS alert', [
                        'event' => $event->value,
                        'admin_id' => $admin->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify admins by SMS', ['event' => $event->value, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Platform direct customers only — reseller-managed customers are alerted via Telegram/email.
     */
    public function shouldNotifyAdminBySmsForCustomer(User $customer): bool
    {
        return $customer->reseller_id === null;
    }

    protected function shouldNotifyAdminBySmsForResellerDomainOrder(ResellerDomainOrder $order, string $stage): bool
    {
        if ($order->isPlatformOrder() || $order->isSelfOrder()) {
            return true;
        }

        // Reseller's customer placed the order — SMS only when reseller pushes for admin registrar action.
        return in_array($stage, ['pushed', 'provisioned', 'completed'], true);
    }

    public function notifyAdminResellerDomainOrder(ResellerDomainOrder $order, string $stage, string $paymentMethod = 'awaiting payment'): void
    {
        $this->telegram()->resellerDomainOrder($order, $stage, $paymentMethod);

        $order->loadMissing('reseller', 'customer');
        $domain = $order->fullDomainName();
        $wholesale = number_format((float) $order->wholesale_amount, 0);
        $retail = number_format((float) $order->retail_amount, 0);
        $event = $stage === 'pushed'
            ? NotificationEvent::AdminResellerDomainPush
            : NotificationEvent::AdminNewOrder;

        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $message = match ($stage) {
            'placed' => $order->isPlatformOrder()
                ? "ALERT: Platform customer {$order->customer->name} placed domain order {$domain}. Amount: KES ".number_format($order->displayAmount(), 0).'. Payment: '.ucfirst($paymentMethod).'.'
                : ($order->isSelfOrder()
                    ? "ALERT: Reseller {$order->reseller->name} placed domain order {$domain}. Wholesale: KES {$wholesale}. Payment: ".ucfirst($paymentMethod).'.'
                    : "ALERT: Domain order {$domain} for {$order->customer->name} via reseller {$order->reseller->name}. Retail: KES {$retail}. Payment: ".ucfirst($paymentMethod).'.'),
            'customer_paid' => $order->isPlatformOrder()
                ? "ALERT: {$order->customer->name} paid for {$domain} (platform direct). Awaiting registrar fulfillment. Amount: KES ".number_format($order->displayAmount(), 0).'.'
                : "ALERT: {$order->customer->name} paid for {$domain} (reseller {$order->reseller->name}). Awaiting push. Wholesale: KES {$wholesale}.",
            'pushed' => $order->isPlatformOrder()
                ? "ALERT: Platform domain {$domain} paid by {$order->customer->name}. Register at registrar now. Amount: KES ".number_format($order->displayAmount(), 0).'.'
                : "ALERT: Reseller {$order->reseller->name} pushed {$domain} for registration. Wholesale: KES {$wholesale}. Fulfill now.",
            'provisioned' => $order->isPlatformOrder()
                ? "ALERT: Platform domain {$domain} registered for {$order->customer->name}. Amount: KES ".number_format($order->displayAmount(), 0).'.'
                : "ALERT: Reseller {$order->reseller->name} registered {$domain} for {$order->customer->name} (no customer invoice). Wholesale: KES {$wholesale}.",
            'completed' => $order->isPlatformOrder()
                ? "ALERT: Platform domain {$domain} registered for {$order->customer->name}. Amount: KES ".number_format($order->displayAmount(), 0).'.'
                : "ALERT: Domain {$domain} registered for {$order->customer->name} via reseller {$order->reseller->name}. Wholesale: KES {$wholesale}.",
            default => "ALERT: Domain order update for {$domain} ({$stage}).",
        };

        if ($stage === 'pushed') {
            $subject = ($order->isPlatformOrder() ? 'Platform domain order' : 'Reseller domain order').' - '.$domain;
            $this->emailDelivery->sendToAdmins(new AdminResellerDomainPushMail($order), $subject, $event);
        } elseif ($this->preferences->isGloballyEnabled(NotificationEvent::AdminNewOrder)) {
            $this->emailDelivery->sendTemplated(null, NotificationEvent::AdminNewOrder, [
                'order_number' => 'DOM-'.$order->id,
                'customer_name' => $order->isPlatformOrder()
                    ? $order->customer->name.' (platform direct)'
                    : ($order->isSelfOrder()
                        ? $order->reseller->name.' (reseller self-order)'
                        : $order->customer->name.' via '.$order->reseller->name),
                'payment_method' => ucfirst($paymentMethod),
                'amount' => 'KES '.number_format((float) ($order->retail_amount + $order->wholesale_amount), 2),
            ]);
        }

        if ($this->shouldNotifyAdminBySmsForResellerDomainOrder($order, $stage)) {
            $this->sendAdminSmsAlert($event, $message);
        }
    }

    public function notifyAdminResellerCustomerInvoiceOrder(
        User $reseller,
        User $customer,
        Invoice $invoice,
        string $summary,
        string $paymentMethod = 'awaiting payment',
    ): void {
        $this->telegram()->resellerCustomerOrderPlaced($reseller, $customer, $invoice, $summary, $paymentMethod);

        $event = NotificationEvent::AdminNewOrder;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $this->emailDelivery->sendTemplated(null, $event, [
            'order_number' => $invoice->invoice_number,
            'customer_name' => $customer->name.' via '.$reseller->name,
            'payment_method' => ucfirst($paymentMethod),
            'amount' => 'KES '.number_format((float) $invoice->total, 2),
        ]);
    }

    public function notifyAdminDomainRenewalPushed(DomainRenewalOrder $renewalOrder, Order $adminOrder, Invoice $adminInvoice): void
    {
        $event = NotificationEvent::AdminNewOrder;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $renewalOrder->loadMissing('domain', 'user');
        $domain = $renewalOrder->domain;
        $domainName = $domain->name.$domain->extension;
        $amount = number_format((float) $adminInvoice->total, 0);

        $message = "ALERT: Domain renewal {$domainName} for {$renewalOrder->user->name} pushed to admin. Order {$adminOrder->order_number}. KES {$amount}.";

        $this->emailDelivery->sendTemplated(null, $event, [
            'order_number' => $adminOrder->order_number,
            'customer_name' => $renewalOrder->user->name,
            'payment_method' => 'Renewal',
            'amount' => 'KES '.number_format((float) $adminInvoice->total, 2),
        ]);

        $this->sendAdminSmsAlert($event, $message);
    }

    public function notifyDomainRenewalCompleted(
        DomainRenewalOrder $renewalOrder,
        Domain $domain,
        int $years,
    ): void {
        $event = NotificationEvent::DomainRenewalCompleted;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $recipient = app(DomainRenewalService::class)->renewalNotificationRecipient($renewalOrder);
        if (! $recipient || ! $recipient->email) {
            return;
        }

        $renewalOrder->loadMissing('domain.user', 'user');
        $endCustomer = null;
        $domainOwner = $domain->user;

        if ($recipient->is_reseller && $domainOwner && $domainOwner->id !== $recipient->id) {
            $endCustomer = $domainOwner->name;
        }

        $subject = 'Domain renewed — '.$domain->fqdn();
        $this->sendPlatformEmail(
            $recipient->email,
            new DomainRenewalCompletedMail(
                $renewalOrder,
                $domain,
                $recipient,
                $years,
                $domain->expires_at ?? now(),
                $endCustomer,
            ),
            $subject,
            $event,
            $recipient,
        );

        if ($recipient->phone && $this->smsService->isConfigured()) {
            try {
                $company = $this->brandingResolver->defaults()['company_name'];
                $expiry = ($domain->expires_at ?? now())->format('M d, Y');
                $this->smsService->send(
                    $recipient->phone,
                    "{$company}: {$domain->fqdn()} renewed for {$years} year(s). New expiry: {$expiry}.",
                );
            } catch (\Exception $e) {
                Log::error('Failed to send domain renewal completed SMS', [
                    'renewal_order_id' => $renewalOrder->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function notifyInvoiceGenerated(Invoice $invoice): void
    {
        $invoice->loadMissing('user');
        $invoice->loadItemsForDisplay();
        $this->telegram()->invoiceGenerated($invoice);

        $event = NotificationEvent::InvoiceGenerated;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Invoice '.$invoice->invoice_number.' Generated';
        $this->sendCustomerEmail($invoice->user, new InvoiceGeneratedMail($invoice), $subject, $event);

        if ($invoice->user->phone) {
            try {
                $message = $this->renderTemplate('invoice_generated', [
                    'customer_name' => $invoice->user->name,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => 'Ksh '.number_format($invoice->total, 2),
                    'due_date' => $invoice->due_date?->format('M d, Y') ?? 'TBD',
                    'site_name' => $this->siteNameFor($invoice->user),
                ], 'Invoice '.$invoice->invoice_number.' has been generated. Amount due: Ksh '.number_format($invoice->total, 0).'. Pay online at: '.url('/'));
                $this->sendCustomerSms($invoice->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send invoice generated SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyInvoiceReminder(Invoice $invoice, int $daysBefore): void
    {
        $event = NotificationEvent::InvoiceReminder;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Payment Reminder: Invoice '.$invoice->invoice_number;
        $this->sendCustomerEmail($invoice->user, new InvoiceReminderMail($invoice, $daysBefore), $subject, $event);

        if ($invoice->user->phone) {
            try {
                $fallback = $daysBefore <= 0 ? 'URGENT: Invoice '.$invoice->invoice_number.' is due today!' : 'Reminder: Invoice '.$invoice->invoice_number.' is due in '.$daysBefore.' days.';
                $message = $this->renderTemplate('invoice_reminder', [
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => 'Ksh '.number_format($invoice->total, 2),
                    'days_before' => (string) $daysBefore,
                    'due_date' => $invoice->due_date?->format('M d, Y') ?? 'TBD',
                    'site_name' => $this->siteNameFor($invoice->user),
                ], $fallback);
                $this->sendCustomerSms($invoice->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send invoice reminder SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyInvoiceOverdue(Invoice $invoice): void
    {
        $event = NotificationEvent::InvoiceOverdue;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'URGENT: Invoice '.$invoice->invoice_number.' is Overdue';
        $this->sendCustomerEmail($invoice->user, new InvoiceOverdueMail($invoice), $subject, $event);

        if ($invoice->user->phone) {
            try {
                $message = $this->renderTemplate('invoice_overdue', [
                    'customer_name' => $invoice->user->name,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => 'Ksh '.number_format($invoice->total, 2),
                    'site_name' => $this->siteNameFor($invoice->user),
                ], 'URGENT: Invoice '.$invoice->invoice_number.' is now overdue. Immediate payment required to avoid service suspension.');
                $this->sendCustomerSms($invoice->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send invoice overdue SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyPaymentReceived(Payment|Invoice $source): void
    {
        $event = NotificationEvent::PaymentReceived;

        if ($source instanceof Invoice) {
            $invoice = $source->loadMissing('user');
            $payment = $invoice->payments()
                ->where('status', PaymentStatus::Completed)
                ->latest('paid_at')
                ->first();

            if (! $payment) {
                $this->notifyInvoicePaidWithoutPaymentRecord($invoice);

                return;
            }
        } else {
            $payment = $source->loadMissing('invoice.user');
            $invoice = $payment->invoice;
        }

        $this->telegram()->paymentReceived($payment);

        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Payment Received for Invoice '.$invoice->invoice_number;
        $this->sendCustomerEmail($invoice->user, new PaymentReceivedMail($payment), $subject, $event);

        if ($invoice->user->phone) {
            try {
                $message = $this->renderTemplate('payment_received', [
                    'customer_name' => $invoice->user->name,
                    'amount' => 'Ksh '.number_format($payment->amount, 2),
                    'invoice_number' => $invoice->invoice_number,
                    'site_name' => $this->siteNameFor($invoice->user),
                ], 'Payment of Ksh '.number_format($payment->amount, 0).' received for invoice '.$invoice->invoice_number.'. Thank you!');
                $this->sendCustomerSms($invoice->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send payment received SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    private function notifyInvoicePaidWithoutPaymentRecord(Invoice $invoice): void
    {
        $invoice->loadMissing('user');
        $this->telegram()->invoiceGenerated($invoice);

        $event = NotificationEvent::PaymentReceived;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $data = [
            'customer_name' => $invoice->user->name,
            'amount' => 'Ksh '.number_format($invoice->total, 2),
            'invoice_number' => $invoice->invoice_number,
            'site_name' => $this->siteNameFor($invoice->user),
        ];

        $this->emailDelivery->sendTemplated($invoice->user, $event, $data);

        if ($invoice->user->phone) {
            $message = $this->renderTemplate('payment_received', $data,
                'Payment of Ksh '.number_format($invoice->total, 0).' received for invoice '.$invoice->invoice_number.'. Thank you!');
            $this->sendCustomerSms($invoice->user, $message, $event);
        }
    }

    public function notifyServiceActivated(Service $service): void
    {
        $this->telegram()->serviceLifecycle($service, 'activated');

        $event = NotificationEvent::ServiceActivated;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Your Service "'.$service->name.'" is Now Active';
        $this->sendCustomerEmail($service->user, new ServiceActivatedMail($service), $subject, $event);

        if ($service->user->phone) {
            try {
                $message = $this->renderTemplate('service_activated', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Your service "'.$service->name.'" is now active and ready to use. Log in to your dashboard for details.');
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send service activated SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyServiceSuspended(Service $service): void
    {
        $this->telegram()->serviceLifecycle($service, 'suspended');

        $event = NotificationEvent::ServiceSuspended;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Service Suspension Notice: '.$service->name;
        $this->sendCustomerEmail($service->user, new ServiceSuspendedMail($service), $subject, $event);

        if ($service->user->phone) {
            try {
                $message = $this->renderTemplate('service_suspended', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Your service "'.$service->name.'" has been suspended. Contact support or pay outstanding invoices to restore service.');
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send service suspended SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyServiceUnsuspended(Service $service): void
    {
        $this->telegram()->serviceLifecycle($service, 'unsuspended');

        $event = NotificationEvent::ServiceUnsuspended;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Service Restored: '.$service->name;
        $this->sendCustomerEmail($service->user, new ServiceUnsuspendedMail($service), $subject, $event);

        if ($this->smsService->isConfigured() && $service->user->phone) {
            try {
                $message = $this->renderTemplate('service_unsuspended', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Your service "'.$service->name.'" has been restored. Your account is now active again.');
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send service unsuspended SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyServiceTerminated(Service $service): void
    {
        $this->telegram()->serviceLifecycle($service, 'terminated');

        $event = NotificationEvent::ServiceTerminated;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Service Termination Notice: '.$service->name;
        $this->sendCustomerEmail($service->user, new ServiceTerminatedMail($service), $subject, $event);

        if ($this->smsService->isConfigured() && $service->user->phone) {
            try {
                $message = $this->renderTemplate('service_terminated', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Your service "'.$service->name.'" has been terminated. Contact support if you wish to restore it.');
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send service terminated SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyDomainExpiry(Domain $domain, int $daysUntilExpiry): void
    {
        $event = NotificationEvent::DomainExpiry;
        if (! $this->emailDelivery->mailConfiguredFor($domain->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Domain Expiry Notice: '.$domain->name;
        $this->sendCustomerEmail($domain->user, new DomainExpiryMail($domain, $daysUntilExpiry), $subject, $event);

        if ($this->smsService->isConfigured() && $domain->user->phone) {
            try {
                $fallback = $daysUntilExpiry <= 0 ? 'URGENT: Your domain '.$domain->name.' has expired!' : 'Your domain '.$domain->name.' expires in '.$daysUntilExpiry.' days. Renew now!';
                $message = $this->renderTemplate('domain_expiry', [
                    'customer_name' => $domain->user->name,
                    'domain_name' => $domain->name,
                    'days_until_expiry' => (string) $daysUntilExpiry,
                    'site_name' => $this->siteNameFor($domain->user),
                ], $fallback);
                $this->sendCustomerSms($domain->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send domain expiry SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyDomainRenewalInvoice(Invoice $invoice, Domain $domain): void
    {
        $event = NotificationEvent::InvoiceGenerated;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Domain Renewal Invoice '.$invoice->invoice_number.' for '.$domain->name;
        $this->sendCustomerEmail($invoice->user, new InvoiceGeneratedMail($invoice), $subject, $event);

        if ($this->smsService->isConfigured() && $invoice->user->phone) {
            try {
                $message = $this->renderTemplate('invoice_generated', [
                    'customer_name' => $invoice->user->name,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => 'Ksh '.number_format($invoice->total, 2),
                    'due_date' => $invoice->due_date?->format('M d, Y') ?? 'TBD',
                    'site_name' => $this->siteNameFor($invoice->user),
                ], 'Domain renewal invoice '.$invoice->invoice_number.' for '.$domain->name.' has been generated. Amount due: Ksh '.number_format($invoice->total, 0).'. Pay by '.($invoice->due_date?->format('M d, Y') ?? 'due date').'.');
                $this->sendCustomerSms($invoice->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send domain renewal invoice SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyNewOrder(Order $order, Invoice $invoice, string $paymentMethod = 'unknown', bool $notifyAdmin = true): void
    {
        $this->telegram()->orderPlaced($order, $invoice, $paymentMethod);

        $customerEvent = NotificationEvent::NewOrder;
        $adminEvent = NotificationEvent::AdminNewOrder;

        $order->loadMissing('user');

        if ($this->emailDelivery->mailConfiguredFor($order->user) && $this->preferences->isEmailEnabledForUser($order->user, $customerEvent)) {
            $subject = 'Order Confirmation - '.$order->order_number;
            $this->sendCustomerEmail($order->user, new OrderConfirmationMail($order), $subject, $customerEvent);
        }

        if ($notifyAdmin && $this->preferences->isGloballyEnabled($adminEvent)) {
            $customerName = $order->user->name;
            if ($order->user->reseller_id) {
                $reseller = User::find($order->user->reseller_id);
                if ($reseller) {
                    $customerName .= ' via '.$reseller->name;
                }
            }

            $this->emailDelivery->sendTemplated(null, $adminEvent, [
                'order_number' => $order->order_number,
                'customer_name' => $customerName,
                'payment_method' => ucfirst($paymentMethod),
                'amount' => 'KES '.number_format($invoice->total, 2),
            ]);

            $adminMessage = $this->renderTemplate('admin_new_order', [
                'order_number' => $order->order_number,
                'customer_name' => $customerName,
                'payment_method' => ucfirst($paymentMethod),
                'amount' => 'KES '.number_format($invoice->total, 0),
            ], 'ALERT: New order #'.$order->order_number.' from '.$customerName.'. Payment: '.ucfirst($paymentMethod).'. Amount: KES '.number_format($invoice->total, 0).'.');

            if ($this->shouldNotifyAdminBySmsForCustomer($order->user)) {
                $this->sendAdminSmsAlert($adminEvent, $adminMessage);
            }
        }

        if ($order->user->phone && $this->preferences->isSmsEnabledForUser($order->user, $customerEvent)) {
            try {
                $customerMessage = match ($paymentMethod) {
                    'manual' => 'Your order #'.$order->order_number.' (KES '.number_format($invoice->total, 0).') has been placed. Please complete your bank transfer. An admin will activate your service after payment verification.',
                    default => 'Your order #'.$order->order_number.' (KES '.number_format($invoice->total, 0).') has been placed and payment is being processed. Service credentials will be emailed once provisioned.',
                };
                $this->sendCustomerSms($order->user, $customerMessage, $customerEvent);
            } catch (\Exception $e) {
                Log::error('Failed to send customer order notification SMS', [
                    'order_id' => $order->id,
                    'customer_id' => $order->user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function notifyTicketCreated(Ticket $ticket): void
    {
        $this->telegram()->ticketEvent($ticket, 'created');
        app(TicketNotificationService::class)->notifyCreated($ticket);
    }

    public function notifyTicketReplied(Ticket $ticket, TicketReply $reply): void
    {
        $this->telegram()->ticketEvent($ticket, 'replied');
        app(TicketNotificationService::class)->notifyReplied($ticket, $reply);
    }

    public function notifyManualPaymentSubmitted(Payment $payment): void
    {
        $this->telegram()->manualPaymentSubmitted($payment);

        $event = NotificationEvent::ManualPaymentSubmitted;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $payment->loadMissing('invoice', 'user');
        $subject = 'Manual Payment Submitted - Invoice '.$payment->invoice->invoice_number;
        $this->emailDelivery->sendToAdmins(new AdminManualPaymentMail($payment), $subject, $event);

        $amount = number_format((float) $payment->amount, 0);
        $message = 'ALERT: Manual payment submitted by '.$payment->user->name.' for invoice '.$payment->invoice->invoice_number.'. KES '.$amount.'. Review and approve.';
        $this->sendAdminSmsAlert($event, $message);
    }

    public function notifyContainerBackupCompleted(Service $service, ContainerBackup $backup): void
    {
        $event = NotificationEvent::ContainerBackupCompleted;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Container Backup Completed: '.$service->name;
        $this->sendCustomerEmail($service->user, new ContainerBackupCompletedMail($service, $backup), $subject, $event);

        if ($this->smsService->isConfigured() && $service->user->phone) {
            try {
                $message = $this->renderTemplate('container_backup_completed', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'backup_name' => $backup->backup_name,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Backup of "'.$service->name.'" completed successfully.');
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send container backup completed SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyContainerBackupFailed(Service $service, string $error): void
    {
        $service->loadMissing('user');
        $this->telegram()->systemAlert('Container backup failed', [
            'Service' => $service->name,
            'Customer' => $service->user?->name ?? '—',
            'Error' => Str::limit($error, 500),
        ]);

        $event = NotificationEvent::ContainerBackupFailed;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Container Backup Failed: '.$service->name;
        $this->emailDelivery->sendToAdmins(new ContainerBackupFailedMail($service, $error), $subject, $event);

        if ($this->smsService->isConfigured()) {
            try {
                $adminUsers = User::where('is_admin', true)->whereNotNull('notification_phones')->get();
                foreach ($adminUsers as $admin) {
                    if (! empty($admin->notification_phones) && is_array($admin->notification_phones)) {
                        $message = 'ALERT: Backup failed for service "'.$service->name.'". Error: '.Str::limit($error, 50);
                        $this->smsService->send($admin->notification_phones, $message);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to notify admins about backup failure', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyContainerFailed(Service $service, string $reason): void
    {
        $service->loadMissing('user');
        $this->telegram()->systemAlert('Container failure', [
            'Service' => $service->name,
            'Customer' => $service->user?->name ?? '—',
            'Reason' => Str::limit($reason, 500),
        ]);

        $event = NotificationEvent::ContainerFailed;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Container Failure Alert: '.$service->name;
        $this->sendCustomerEmail($service->user, new ContainerFailedMail($service, $reason), $subject, $event);

        if ($this->smsService->isConfigured() && $service->user->phone) {
            try {
                $message = $this->renderTemplate('container_failed', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'reason' => $reason,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'ALERT: Your container service "'.$service->name.'" has failed. '.Str::limit($reason, 50));
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send container failed SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyContainerAutoRestarted(Service $service, int $attemptCount): void
    {
        $service->loadMissing('user');
        $this->telegram()->systemAlert('Container auto-restarted', [
            'Service' => $service->name,
            'Customer' => $service->user?->name ?? '—',
            'Attempts' => (string) $attemptCount,
        ]);

        $event = NotificationEvent::ContainerRestart;
        if (! $this->emailDelivery->mailConfiguredFor($service->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Container Auto-Restarted: '.$service->name;
        $this->sendCustomerEmail($service->user, new ContainerAutoRestartedMail($service, $attemptCount), $subject, $event);

        if ($this->smsService->isConfigured() && $service->user->phone) {
            try {
                $message = $this->renderTemplate('container_restarted', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'attempt_count' => (string) $attemptCount,
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Your container service "'.$service->name.'" was automatically restarted after '.$attemptCount.' failed attempt(s).');
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send container auto-restart SMS', ['error' => $e->getMessage()]);
            }
        }
    }

    public function notifyAdminNodeOffline(string $subject, string $body): void
    {
        $this->telegram()->systemAlert($subject, ['Details' => Str::limit($body, 800)]);

        $event = NotificationEvent::AdminNodeOffline;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $this->emailDelivery->sendToAdmins(
            new GenericNotificationMail($subject, 'Container Node Offline Alert', $body),
            $subject,
            $event,
            $body
        );
    }

    public function notifyServiceProvisionFailed(Service $service, string $reason): void
    {
        $service->loadMissing('user', 'product');
        $this->telegram()->serviceLifecycle($service, 'provision failed', $reason);

        $event = NotificationEvent::ServiceProvisionFailed;

        if ($this->preferences->isGloballyEnabled($event) && $this->emailDelivery->mailConfiguredFor($service->user)) {
            $subject = 'Service setup failed — '.$service->name;
            $this->sendCustomerEmail(
                $service->user,
                new ServiceProvisionFailedMail($service, $reason),
                $subject,
                $event,
                $reason,
            );

            if ($service->user->phone) {
                $message = $this->renderTemplate('service_provision_failed', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'reason' => Str::limit($reason, 120),
                    'site_name' => $this->siteNameFor($service->user),
                ], 'Setup for "'.$service->name.'" failed. Our team has been notified. Check your dashboard or contact support.');
                $this->sendCustomerSms($service->user, $message, $event);
            }
        }

        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $adminSubject = 'Provisioning failed — '.$service->name.' (#'.$service->id.')';
        $adminBody = "Customer: {$service->user->name} ({$service->user->email})\n"
            ."Service: {$service->name} (#{$service->id})\n"
            ."Reason: {$reason}";

        $this->emailDelivery->sendToAdmins(
            new GenericNotificationMail($adminSubject, 'Service provisioning failed', $adminBody),
            $adminSubject,
            $event,
            $adminBody,
        );

        if ($this->shouldNotifyAdminBySmsForCustomer($service->user)) {
            $adminSmsMessage = 'Provision failed: '.$service->name.' for '.$service->user->name.'. '.Str::limit($reason, 80);
            $this->sendAdminSmsAlert($event, $adminSmsMessage);
        }
    }

    public function notifyPaymentFailed(Payment $payment, string $reason): void
    {
        $payment->loadMissing('invoice.user');
        $this->telegram()->paymentFailed($payment, $reason);

        $invoice = $payment->invoice;
        if (! $invoice?->user) {
            return;
        }

        $event = NotificationEvent::PaymentFailed;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Payment failed — Invoice '.$invoice->invoice_number;
        $this->sendCustomerEmail($invoice->user, new PaymentFailedMail($payment, $reason), $subject, $event, $reason);

        if ($invoice->user->phone) {
            $message = $this->renderTemplate('payment_failed', [
                'customer_name' => $invoice->user->name,
                'invoice_number' => $invoice->invoice_number,
                'amount' => 'Ksh '.number_format($invoice->total, 2),
                'site_name' => $this->siteNameFor($invoice->user),
            ], 'Payment for invoice '.$invoice->invoice_number.' failed. Please retry from your dashboard.');
            $this->sendCustomerSms($invoice->user, $message, $event);
        }
    }

    public function notifySharedHostingCredentials(Service $service): void
    {
        $service->loadMissing('user', 'product');
        if ($service->provisioning_driver_key !== 'directadmin' && $service->product?->provisioning_driver_key !== 'directadmin') {
            return;
        }

        $event = NotificationEvent::ServiceActivated;
        if (! $this->emailDelivery->mailConfiguredFor($service->user)) {
            return;
        }

        $subject = 'Hosting control panel login — '.$service->name;
        $this->sendCustomerEmail($service->user, new SharedHostingCredentialsMail($service), $subject, $event);
    }

    public function notifyResellerSubscriptionInvoice(Invoice $invoice): void
    {
        if ($invoice->type !== 'reseller_subscription' || $invoice->isPaid()) {
            return;
        }

        $this->notifyInvoiceGenerated($invoice);
    }

    public function notifyResellerSuspended(User $reseller, string $reason): void
    {
        $this->telegram()->resellerEvent($reseller, 'Reseller suspended', ['Reason' => Str::limit($reason, 500)]);

        $event = NotificationEvent::ResellerSuspended;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $company = $this->brandingResolver->forReseller($reseller)['company_name'];
        $packagesUrl = route('reseller.packages.index');

        if ($reseller->email) {
            $subject = 'Reseller account suspended — '.$company;
            $body = "Hello {$reseller->name},\n\n"
                ."Your reseller account has been suspended.\n\n"
                ."Reason: {$reason}\n\n"
                ."Pay your package subscription invoice to restore access:\n{$packagesUrl}";

            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new GenericNotificationMail($subject, 'Reseller account suspended', $body),
                $subject,
                $event,
                $reseller,
                $body,
            );
        }

        if ($reseller->phone && $this->smsService->isConfigured()) {
            try {
                $this->smsService->send(
                    $reseller->phone,
                    "{$company}: Your reseller account is suspended ({$reason}). Pay your package invoice to restore access: {$packagesUrl}",
                );
            } catch (\Exception $e) {
                Log::error('Failed to send reseller suspension SMS', ['reseller_id' => $reseller->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function notifyResellerDiskPoolWarning(User $reseller, float $usedGb, float $poolGb): void
    {
        $this->telegram()->resellerEvent($reseller, 'Disk pool exceeded', [
            'Used' => number_format($usedGb, 1).' GB',
            'Pool limit' => number_format($poolGb, 1).' GB',
        ]);

        $event = NotificationEvent::ResellerDiskPoolWarning;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $company = $this->brandingResolver->forReseller($reseller)['company_name'];
        $used = number_format($usedGb, 1);
        $pool = number_format($poolGb, 1);

        if ($reseller->email) {
            $subject = "Disk pool exceeded — {$company}";
            $body = "Hello {$reseller->name},\n\n"
                ."Your managed hosting disk usage ({$used} GB) exceeds your package pool ({$pool} GB).\n\n"
                .'New customer provisioning may be blocked until usage is reduced or your package is upgraded.';

            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new GenericNotificationMail($subject, 'Disk pool exceeded', $body),
                $subject,
                $event,
                $reseller,
                $body,
            );
        }

        if ($reseller->phone && $this->smsService->isConfigured()) {
            try {
                $this->smsService->send(
                    $reseller->phone,
                    "{$company}: Disk pool exceeded ({$used}/{$pool} GB). Reduce usage or upgrade to avoid blocked orders.",
                );
            } catch (\Exception $e) {
                Log::error('Failed to send disk pool warning SMS', ['reseller_id' => $reseller->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function notifyResellerDomainOrdersExpired(User $reseller, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->telegram()->resellerEvent($reseller, 'Queued domain orders expired', [
            'Expired orders' => (string) $count,
        ]);

        $event = NotificationEvent::ResellerDomainOrderExpired;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $ordersUrl = route('reseller.domain-orders.index');
        $company = $this->brandingResolver->forReseller($reseller)['company_name'];

        if ($reseller->email) {
            $subject = "{$count} queued domain order(s) expired";
            $body = "Hello {$reseller->name},\n\n"
                ."{$count} queued domain registration order(s) expired because they were not pushed before the deadline.\n\n"
                ."Review them here: {$ordersUrl}";

            $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                new GenericNotificationMail($subject, 'Queued domain orders expired', $body),
                $subject,
                $event,
                $reseller,
                $body,
            );
        }

        if ($reseller->phone && $this->smsService->isConfigured()) {
            try {
                $this->smsService->send(
                    $reseller->phone,
                    "{$company}: {$count} queued domain order(s) expired. Review: {$ordersUrl}",
                );
            } catch (\Exception $e) {
                Log::error('Failed to send domain order expiry SMS', ['reseller_id' => $reseller->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function notifyDomainTransferInitiated(Domain $domain): void
    {
        $domain->loadMissing('user');
        $event = NotificationEvent::DomainTransfer;

        if (! $domain->user || ! $this->emailDelivery->mailConfiguredFor($domain->user)) {
            return;
        }

        if (! $this->preferences->isEmailEnabledForUser($domain->user, $event)) {
            return;
        }

        $fqdn = format_domain_name($domain->name, $domain->extension);
        $subject = 'Domain transfer initiated — '.$fqdn;

        $this->sendCustomerEmail(
            $domain->user,
            new DomainTransferInitiatedMail($domain),
            $subject,
            $event,
        );
    }

    public function notifyDomainTransferCompleted(Domain $domain): void
    {
        $domain->loadMissing('user');
        $event = NotificationEvent::DomainTransferCompleted;
        if (! $domain->user || ! $this->emailDelivery->mailConfiguredFor($domain->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $fqdn = $domain->name.$domain->extension;
        $subject = 'Domain transfer completed — '.$fqdn;
        $body = "Your domain transfer for {$fqdn} has completed successfully. The domain is now active on your account.";

        $this->sendCustomerEmail(
            $domain->user,
            new GenericNotificationMail($subject, 'Domain transfer completed', $body),
            $subject,
            NotificationEvent::DomainTransfer,
            $body,
        );

        if ($domain->user->phone) {
            $this->sendCustomerSms(
                $domain->user,
                "Domain transfer completed: {$fqdn} is now active on your account.",
                NotificationEvent::DomainTransfer,
            );
        }
    }

    public function notifyDomainTransferFailed(Domain $domain, string $reason): void
    {
        $domain->loadMissing('user');
        if (! $domain->user || ! $this->emailDelivery->mailConfiguredFor($domain->user) || ! $this->preferences->isGloballyEnabled(NotificationEvent::DomainTransferFailed)) {
            return;
        }

        $fqdn = $domain->name.$domain->extension;
        $subject = 'Domain transfer failed — '.$fqdn;
        $body = "Your domain transfer for {$fqdn} could not be completed.\n\nReason: {$reason}";

        $this->sendCustomerEmail(
            $domain->user,
            new GenericNotificationMail($subject, 'Domain transfer failed', $body),
            $subject,
            NotificationEvent::DomainTransfer,
            $body,
        );

        if ($domain->user->phone) {
            $this->sendCustomerSms(
                $domain->user,
                "Domain transfer failed for {$fqdn}. {$reason}",
                NotificationEvent::DomainTransfer,
            );
        }
    }

    public function notifyPasswordChanged(User $user): void
    {
        $event = NotificationEvent::PasswordChanged;
        if ($this->emailDelivery->mailConfiguredFor($user) && $this->preferences->isGloballyEnabled($event)) {
            $siteName = $this->siteNameFor($user);
            $subject = 'Password changed — '.$siteName;
            $this->sendCustomerEmail($user, new PasswordChangedMail($user), $subject, $event);
        }

        if ($this->authCodeSms->canSend($user)) {
            $siteName = $this->authCodeSms->siteNameFor($user);
            $this->authCodeSms->send(
                $user,
                "Your {$siteName} password was changed successfully. If this wasn't you, contact support immediately.",
            );
        }
    }

    public function notifyManualPaymentRejected(Payment $payment, string $rejectionReason): void
    {
        $payment->loadMissing('invoice.user');
        $this->telegram()->manualPaymentRejected($payment, $rejectionReason);

        $invoice = $payment->invoice;
        if (! $invoice?->user) {
            return;
        }

        $event = NotificationEvent::ManualPaymentRejected;
        if (! $this->emailDelivery->mailConfiguredFor($invoice->user) || ! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $subject = 'Manual payment rejected — Invoice '.$invoice->invoice_number;
        $this->sendCustomerEmail(
            $invoice->user,
            new ManualPaymentRejectedMail($payment, $rejectionReason),
            $subject,
            $event,
            $rejectionReason,
        );

        if ($invoice->user->phone) {
            $message = $this->renderTemplate('manual_payment_rejected', [
                'customer_name' => $invoice->user->name,
                'invoice_number' => $invoice->invoice_number,
                'amount' => 'Ksh '.number_format($payment->amount, 2),
                'rejection_reason' => $rejectionReason,
                'site_name' => $this->siteNameFor($invoice->user),
            ], 'Your manual payment for invoice '.$invoice->invoice_number.' was rejected. Reason: '.$rejectionReason);
            $this->sendCustomerSms($invoice->user, $message, $event);
        }
    }

    public function notifyServerCredentials(Service $service): void
    {
        $service->loadMissing('user', 'product');
        if (! $service->user) {
            return;
        }

        $event = NotificationEvent::ServiceActivated;
        if (! $this->emailDelivery->mailConfiguredFor($service->user)) {
            return;
        }

        $subject = 'Your '.$service->product?->name.' is ready — login details inside';
        $this->sendCustomerEmail($service->user, new ServerCredentialsMail($service), $subject, $event);
    }

    public function notifyResellerSslProvisionFailed(User $reseller, string $domain, string $reason): void
    {
        $this->telegram()->resellerEvent($reseller, 'SSL provisioning failed', [
            'Domain' => $domain,
            'Reason' => Str::limit($reason, 500),
        ]);

        $event = NotificationEvent::ResellerSslProvisionFailed;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $company = $this->brandingResolver->forReseller($reseller)['company_name'];
        $settingsUrl = route('reseller.settings.index');

        if ($reseller->email && $this->emailDelivery->mailConfiguredFor(null)) {
            $subject = 'SSL provisioning failed — '.$domain;
            $body = "Hello {$reseller->name},\n\n"
                ."SSL certificate provisioning failed for {$domain}.\n\n"
                ."Reason: {$reason}\n\n"
                ."Review DNS settings and retry from your reseller settings:\n{$settingsUrl}";

            $this->sendPlatformEmail(
                $reseller->email,
                new GenericNotificationMail($subject, 'SSL provisioning failed', $body),
                $subject,
                $event,
                $reseller,
                $body,
            );
        }

        if ($reseller->phone && $this->smsService->isConfigured()) {
            $message = $this->renderTemplate('reseller_ssl_provision_failed', [
                'reseller_name' => $reseller->name,
                'domain' => $domain,
                'reason' => $reason,
                'site_name' => $company,
            ], "SSL provisioning failed for {$domain}. {$reason}");
            $this->smsService->send($reseller->phone, $message);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $metricsAtRisk
     */
    public function notifyHostingPackageUsageWarning(Service $service, array $metricsAtRisk, ?Product $recommendedUpgrade = null): void
    {
        $service->loadMissing('user', 'product');
        if (! $service->user) {
            return;
        }

        $event = NotificationEvent::HostingPackageUsageWarning;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        $upgradeUrl = route('customer.services.upgrade', $service);
        $peakPercent = (int) round(collect($metricsAtRisk)->max(fn ($metric) => $metric['percent'] ?? 0));
        $metricNames = collect($metricsAtRisk)->keys()->map(fn ($key) => match ($key) {
            'bandwidth' => 'bandwidth',
            'database' => 'database',
            default => 'storage',
        })->implode(', ');

        if ($this->emailDelivery->mailConfiguredFor($service->user)) {
            $subject = "Action needed: {$service->name} is at {$peakPercent}% capacity";
            $this->sendCustomerEmail(
                $service->user,
                new HostingPackageUsageWarningMail($service, $metricsAtRisk, $recommendedUpgrade),
                $subject,
                $event,
            );
        }

        if ($service->user->phone) {
            try {
                $planHint = $recommendedUpgrade ? " Upgrade to {$recommendedUpgrade->name}." : '';
                $message = $this->renderTemplate('hosting_package_usage_warning', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'percent' => (string) $peakPercent,
                    'metrics' => $metricNames,
                    'upgrade_url' => $upgradeUrl,
                    'site_name' => $this->siteNameFor($service->user),
                ], "{$service->name} is at {$peakPercent}% of your {$metricNames} limit. Upgrade your plan to avoid interruption.{$planHint} -{$this->siteNameFor($service->user)}");
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send hosting package usage warning SMS', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function notifyHostingUpgradeCompleted(Service $service, Product $previousProduct, Product $newProduct): void
    {
        $service->loadMissing('user');
        if (! $service->user) {
            return;
        }

        $event = NotificationEvent::HostingUpgradeCompleted;
        if (! $this->preferences->isGloballyEnabled($event)) {
            return;
        }

        if ($this->emailDelivery->mailConfiguredFor($service->user)) {
            $subject = "Plan upgraded: {$service->name}";
            $this->sendCustomerEmail(
                $service->user,
                new HostingUpgradeCompletedMail($service, $previousProduct, $newProduct),
                $subject,
                $event,
            );
        }

        if ($service->user->phone) {
            try {
                $message = $this->renderTemplate('hosting_upgrade_completed', [
                    'customer_name' => $service->user->name,
                    'service_name' => $service->name,
                    'old_plan' => $previousProduct->name,
                    'new_plan' => $newProduct->name,
                    'site_name' => $this->siteNameFor($service->user),
                ], "{$service->name} upgraded from {$previousProduct->name} to {$newProduct->name}. Your new limits are active. -{$this->siteNameFor($service->user)}");
                $this->sendCustomerSms($service->user, $message, $event);
            } catch (\Exception $e) {
                Log::error('Failed to send hosting upgrade completed SMS', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $usernames
     */
    public function notifyResellerUnlinkedDirectAdminAccounts(User $reseller, int $count, array $usernames = []): void
    {
        if ($count <= 0) {
            return;
        }

        $this->telegram()->resellerEvent($reseller, 'Unlinked DirectAdmin accounts', [
            'Count' => (string) $count,
        ]);

        $customersUrl = route('reseller.customers.index', ['link' => 'unlinked']);
        $sample = array_slice($usernames, 0, 5);
        $sampleText = $sample !== [] ? implode(', ', $sample) : 'n/a';

        if ($reseller->email && $this->emailDelivery->mailConfiguredFor(null)) {
            $subject = "{$count} DirectAdmin account(s) need linking";
            $body = "Hello {$reseller->name},\n\n"
                ."{$count} hosting account(s) on your DirectAdmin server are not linked to the platform yet.\n\n"
                ."Sample usernames: {$sampleText}\n\n"
                ."Link them from your customer list to enable portal access and auto-billing:\n{$customersUrl}";

            try {
                $this->emailDelivery->sendPlatformMailable(
                    $reseller->email,
                    new GenericNotificationMail($subject, 'DirectAdmin accounts need linking', $body),
                    $subject,
                    NotificationEvent::ResellerDiskPoolWarning,
                    $reseller,
                    $body,
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to email reseller about unlinked DirectAdmin accounts', [
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Notified reseller about unlinked DirectAdmin accounts', [
            'reseller_id' => $reseller->id,
            'count' => $count,
        ]);
    }
}
