<?php

namespace App\Services;

use App\Mail\TemplatedNotificationMail;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ResellerEmailTemplateService
{
    private const SETTINGS_KEY = 'email_templates';

    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    /**
     * Customer-facing emails sent via the reseller's SMTP.
     *
     * @return list<array{
     *     event_key: string,
     *     name: string,
     *     subject: string,
     *     body: string,
     *     description: string,
     *     available_variables: list<string>
     * }>
     */
    public static function defaultTemplates(): array
    {
        return [
            [
                'event_key' => 'account_welcome',
                'name' => 'Account Welcome',
                'subject' => 'Welcome to {site_name} — Your Account Details',
                'body' => "Hi {customer_name},\n\nYour customer account is ready.\n\nEmail: {customer_email}\nTemporary password: {password}\n\nSign in: {login_url}\n\nPlease change your password after logging in.\n\n— {site_name}",
                'description' => 'Sent when you create a customer and choose to email their login details.',
                'available_variables' => ['customer_name', 'customer_email', 'password', 'login_url', 'site_name', 'support_email'],
            ],
            [
                'event_key' => 'customer_account_transferred',
                'name' => 'Account Assigned / Transferred',
                'subject' => 'Your {site_name} account has been updated',
                'body' => "Hi {customer_name},\n\nYour account is now managed by {site_name}.\n\nSign in: {login_url}\n\n— {site_name}",
                'description' => 'Sent when a customer is transferred or assigned to your account.',
                'available_variables' => ['customer_name', 'login_url', 'site_name', 'support_email'],
            ],
            [
                'event_key' => 'invoice_generated',
                'name' => 'Invoice Generated',
                'subject' => 'Invoice {invoice_number} Generated',
                'body' => "Hi {customer_name},\n\nInvoice {invoice_number} for {amount} is ready.\nDue date: {due_date}\n\nPay online: {portal_url}\n\n— {site_name}",
                'description' => 'Sent when a new invoice is created for your customer.',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'due_date', 'portal_url', 'site_name', 'support_email'],
            ],
            [
                'event_key' => 'invoice_reminder',
                'name' => 'Invoice Reminder',
                'subject' => 'Payment Reminder - Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nReminder: Invoice {invoice_number} for {amount} is due in {days_before} day(s) (due {due_date}).\n\nPay online: {portal_url}\n\n— {site_name}",
                'description' => 'Sent before an invoice due date.',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'days_before', 'due_date', 'portal_url', 'site_name'],
            ],
            [
                'event_key' => 'invoice_overdue',
                'name' => 'Invoice Overdue',
                'subject' => 'URGENT: Invoice {invoice_number} is Overdue',
                'body' => "Hi {customer_name},\n\nInvoice {invoice_number} for {amount} is now overdue. Please pay immediately to avoid service interruption.\n\nPay online: {portal_url}\n\n— {site_name}",
                'description' => 'Sent when an invoice becomes overdue.',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'portal_url', 'site_name'],
            ],
            [
                'event_key' => 'payment_received',
                'name' => 'Payment Received',
                'subject' => 'Payment Received - Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nWe received your payment of {amount} for invoice {invoice_number}.\n\nThank you!\n\n— {site_name}",
                'description' => 'Sent when a customer payment is confirmed.',
                'available_variables' => ['customer_name', 'amount', 'invoice_number', 'site_name'],
            ],
            [
                'event_key' => 'payment_failed',
                'name' => 'Payment Failed',
                'subject' => 'Payment failed — Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nYour payment for invoice {invoice_number} ({amount}) could not be completed.\n\nReason: {reason}\n\nPlease retry from your dashboard.\n\n— {site_name}",
                'description' => 'Sent when an online payment fails.',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'manual_payment_rejected',
                'name' => 'Manual Payment Rejected',
                'subject' => 'Manual payment rejected — Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nYour manual payment submission for invoice {invoice_number} ({amount}) was rejected.\n\nReason: {rejection_reason}\n\n— {site_name}",
                'description' => 'Sent when a manual payment proof is rejected.',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'rejection_reason', 'site_name'],
            ],
            [
                'event_key' => 'new_order',
                'name' => 'Order Confirmation',
                'subject' => 'Order Confirmation - {order_number}',
                'body' => "Hi {customer_name},\n\nThank you for your order #{order_number}.\n\nTotal: {amount}\n\nWe will notify you when payment is confirmed.\n\n— {site_name}",
                'description' => 'Sent when a customer places a new order.',
                'available_variables' => ['customer_name', 'order_number', 'amount', 'site_name'],
            ],
            [
                'event_key' => 'service_activated',
                'name' => 'Service Activated',
                'subject' => 'Service Activated - {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" is now active.\n\n— {site_name}",
                'description' => 'Sent when a service becomes active.',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'shared_hosting_credentials',
                'name' => 'Shared Hosting Credentials',
                'subject' => 'Hosting login details — {service_name}',
                'body' => "Hi {customer_name},\n\nYour hosting account for \"{service_name}\" is ready.\n\nControl panel: {panel_url}\nUsername: {username}\nPassword: {password}\n\n— {site_name}",
                'description' => 'Sent with DirectAdmin / shared hosting login details.',
                'available_variables' => ['customer_name', 'service_name', 'panel_url', 'username', 'password', 'site_name'],
            ],
            [
                'event_key' => 'server_credentials',
                'name' => 'Server Credentials',
                'subject' => 'Server access details — {service_name}',
                'body' => "Hi {customer_name},\n\nYour server \"{service_name}\" is ready.\n\nIP: {server_ip}\nUsername: {username}\nPassword: {password}\n\n— {site_name}",
                'description' => 'Sent with VPS / server access details.',
                'available_variables' => ['customer_name', 'service_name', 'server_ip', 'username', 'password', 'site_name'],
            ],
            [
                'event_key' => 'service_suspended',
                'name' => 'Service Suspended',
                'subject' => 'Service Suspended - {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" has been suspended.\n\nPlease pay any outstanding invoices or contact support.\n\n— {site_name}",
                'description' => 'Sent when a service is suspended.',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_unsuspended',
                'name' => 'Service Restored',
                'subject' => 'Service Restored - {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" has been restored.\n\n— {site_name}",
                'description' => 'Sent when a suspended service is restored.',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_terminated',
                'name' => 'Service Terminated',
                'subject' => 'Service Terminated - {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" has been terminated.\n\n— {site_name}",
                'description' => 'Sent when a service is terminated.',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_provision_failed',
                'name' => 'Service Provision Failed',
                'subject' => 'Service setup failed — {service_name}',
                'body' => "Hi {customer_name},\n\nWe could not automatically set up your service \"{service_name}\".\n\nReason: {reason}\n\nOur team has been notified.\n\n— {site_name}",
                'description' => 'Sent when automatic provisioning fails.',
                'available_variables' => ['customer_name', 'service_name', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'domain_expiry',
                'name' => 'Domain Expiry Reminder',
                'subject' => 'Domain expiring soon — {domain_name}',
                'body' => "Hi {customer_name},\n\nYour domain {domain_name} expires in {days_until_expiry} day(s) (on {expiry_date}).\n\nRenew from your dashboard to avoid downtime.\n\n— {site_name}",
                'description' => 'Sent before a domain expires.',
                'available_variables' => ['customer_name', 'domain_name', 'days_until_expiry', 'expiry_date', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer',
                'name' => 'Domain Transfer Initiated',
                'subject' => 'Domain transfer started — {domain_name}',
                'body' => "Hi {customer_name},\n\nWe have started transferring {domain_name}. You will receive another email when it completes.\n\n— {site_name}",
                'description' => 'Sent when a domain transfer is initiated.',
                'available_variables' => ['customer_name', 'domain_name', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer_completed',
                'name' => 'Domain Transfer Completed',
                'subject' => 'Domain transfer completed — {domain_name}',
                'body' => "Hi {customer_name},\n\nYour domain transfer for {domain_name} has completed successfully.\n\n— {site_name}",
                'description' => 'Sent when a domain transfer completes.',
                'available_variables' => ['customer_name', 'domain_name', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer_failed',
                'name' => 'Domain Transfer Failed',
                'subject' => 'Domain transfer failed — {domain_name}',
                'body' => "Hi {customer_name},\n\nYour domain transfer for {domain_name} could not be completed.\n\nReason: {reason}\n\n— {site_name}",
                'description' => 'Sent when a domain transfer fails.',
                'available_variables' => ['customer_name', 'domain_name', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'ticket_created',
                'name' => 'Support Ticket Created',
                'subject' => 'Support Ticket #{ticket_id} Created',
                'body' => "Hi {customer_name},\n\nWe received your support ticket #{ticket_id}: {ticket_title}\n\nOur team will respond soon.\n\n— {site_name}",
                'description' => 'Sent when a customer opens a support ticket.',
                'available_variables' => ['customer_name', 'ticket_id', 'ticket_title', 'site_name'],
            ],
            [
                'event_key' => 'ticket_replied',
                'name' => 'Support Ticket Reply',
                'subject' => 'New reply on Ticket #{ticket_id}',
                'body' => "Hi {customer_name},\n\nThere is a new reply on ticket #{ticket_id}: {ticket_title}\n\nView it in your portal: {portal_url}\n\n— {site_name}",
                'description' => 'Sent when staff reply to a customer ticket.',
                'available_variables' => ['customer_name', 'ticket_id', 'ticket_title', 'portal_url', 'site_name'],
            ],
            [
                'event_key' => 'password_changed',
                'name' => 'Password Changed',
                'subject' => 'Password changed — {site_name}',
                'body' => "Hi {customer_name},\n\nYour account password was changed successfully.\n\nIf you did not make this change, contact support immediately.\n\n— {site_name}",
                'description' => 'Sent when a customer changes their password.',
                'available_variables' => ['customer_name', 'site_name', 'support_email'],
            ],
            [
                'event_key' => 'password_reset',
                'name' => 'Password Reset',
                'subject' => 'Reset your {site_name} password',
                'body' => "Hi {customer_name},\n\nReset your password using this link:\n{reset_url}\n\nIf you did not request this, you can ignore this email.\n\n— {site_name}",
                'description' => 'Sent when a customer requests a password reset.',
                'available_variables' => ['customer_name', 'reset_url', 'site_name', 'support_email'],
            ],
            [
                'event_key' => 'email_verification',
                'name' => 'Email Verification Code',
                'subject' => 'Your verification code — {site_name}',
                'body' => "Hi {customer_name},\n\nYour verification code is: {code}\n\nIt expires in a few minutes.\n\n— {site_name}",
                'description' => 'Sent with the email verification OTP.',
                'available_variables' => ['customer_name', 'code', 'site_name'],
            ],
            [
                'event_key' => 'two_factor_code',
                'name' => 'Two-Factor Code',
                'subject' => 'Your login code — {site_name}',
                'body' => "Hi {customer_name},\n\nYour two-factor login code is: {code}\n\nIt expires in {expiry_minutes} minutes.\n\n— {site_name}",
                'description' => 'Sent with the 2FA login code.',
                'available_variables' => ['customer_name', 'code', 'expiry_minutes', 'site_name'],
            ],
            [
                'event_key' => 'hosting_package_usage_warning',
                'name' => 'Hosting Usage Warning',
                'subject' => 'Hosting usage warning — {service_name}',
                'body' => "Hi {customer_name},\n\nYour hosting package \"{service_name}\" is approaching its limits ({metrics}).\n\nConsider upgrading to avoid interruption.\n\n— {site_name}",
                'description' => 'Sent when disk/email/bandwidth usage is high.',
                'available_variables' => ['customer_name', 'service_name', 'metrics', 'site_name'],
            ],
            [
                'event_key' => 'hosting_upgrade_completed',
                'name' => 'Hosting Upgrade Completed',
                'subject' => 'Hosting upgraded — {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" was upgraded from {previous_plan} to {new_plan}.\n\n— {site_name}",
                'description' => 'Sent after a hosting package upgrade completes.',
                'available_variables' => ['customer_name', 'service_name', 'previous_plan', 'new_plan', 'site_name'],
            ],
            [
                'event_key' => 'container_backup_completed',
                'name' => 'Container Backup Completed',
                'subject' => 'Backup completed — {service_name}',
                'body' => "Hi {customer_name},\n\nA backup for \"{service_name}\" completed successfully.\n\n— {site_name}",
                'description' => 'Sent when a container backup finishes.',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'container_failed',
                'name' => 'Container Failed',
                'subject' => 'Container issue — {service_name}',
                'body' => "Hi {customer_name},\n\nYour container \"{service_name}\" reported a problem.\n\nReason: {reason}\n\n— {site_name}",
                'description' => 'Sent when a container fails.',
                'available_variables' => ['customer_name', 'service_name', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'container_restart',
                'name' => 'Container Auto-Restarted',
                'subject' => 'Container restarted — {service_name}',
                'body' => "Hi {customer_name},\n\nYour container \"{service_name}\" was automatically restarted (attempt {attempt_count}).\n\n— {site_name}",
                'description' => 'Sent when a container is auto-restarted.',
                'available_variables' => ['customer_name', 'service_name', 'attempt_count', 'site_name'],
            ],
        ];
    }

    public function listForReseller(User $reseller): Collection
    {
        $overrides = $this->overrides($reseller);

        return collect(self::defaultTemplates())
            ->map(function (array $default) use ($overrides) {
                $eventKey = $default['event_key'];
                $override = $overrides[$eventKey] ?? null;
                $isOverridden = is_array($override);

                return [
                    'id' => $eventKey,
                    'event_key' => $eventKey,
                    'name' => $default['name'],
                    'description' => $default['description'],
                    'available_variables' => $default['available_variables'],
                    'subject' => is_string($override['subject'] ?? null) ? $override['subject'] : $default['subject'],
                    'body' => is_string($override['body'] ?? null) ? $override['body'] : $default['body'],
                    'enabled' => array_key_exists('enabled', $override ?? [])
                        ? (bool) $override['enabled']
                        : true,
                    'is_overridden' => $isOverridden,
                    'default_subject' => $default['subject'],
                    'default_body' => $default['body'],
                ];
            })
            ->values();
    }

    /**
     * @return array{subject: string, body: string, enabled: bool, is_overridden: bool}|null
     */
    public function resolve(User $reseller, string $eventKey): ?array
    {
        $default = collect(self::defaultTemplates())->firstWhere('event_key', $eventKey);
        if (! $default) {
            return null;
        }

        $override = $this->overrides($reseller)[$eventKey] ?? null;
        $isOverridden = is_array($override);

        return [
            'subject' => is_string($override['subject'] ?? null) ? $override['subject'] : $default['subject'],
            'body' => is_string($override['body'] ?? null) ? $override['body'] : $default['body'],
            'enabled' => array_key_exists('enabled', $override ?? [])
                ? (bool) $override['enabled']
                : true,
            'is_overridden' => $isOverridden,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}|null Null when template missing; false handled by caller for disabled
     */
    public function render(User $reseller, string $eventKey, array $data): ?array
    {
        $resolved = $this->resolve($reseller, $eventKey);
        if (! $resolved) {
            return null;
        }

        $brandingVars = $this->brandingVariables($reseller, $data['customer'] ?? null);
        $merged = array_merge($brandingVars, $data);
        unset($merged['customer']);

        // Branding company name always wins over any caller-supplied site_name.
        $merged['site_name'] = $brandingVars['site_name'];
        $merged['support_email'] = $merged['support_email'] ?? $brandingVars['support_email'];
        $merged['portal_url'] = $merged['portal_url'] ?? $brandingVars['portal_url'];
        $merged['login_url'] = $merged['login_url'] ?? $brandingVars['login_url'];

        return [
            'subject' => $this->scrubCustomerFacingCopy($this->replacePlaceholders($resolved['subject'], $merged)),
            'body' => $this->scrubCustomerFacingCopy($this->replacePlaceholders($resolved['body'], $merged)),
            'enabled' => $resolved['enabled'],
            'is_overridden' => $resolved['is_overridden'],
        ];
    }

    /**
     * Remove internal "reseller" wording from customer-facing email copy (white-label).
     */
    public function scrubCustomerFacingCopy(string $text): string
    {
        $replacements = [
            '/\byour reseller account\b/iu' => 'your account',
            '/\breseller account\b/iu' => 'account',
            '/\breseller portal\b/iu' => 'client portal',
            '/\breseller dashboard\b/iu' => 'dashboard',
            '/\breseller settings\b/iu' => 'settings',
            '/\bresellers\b/iu' => 'providers',
            '/\breseller-owned\b/iu' => 'your',
            '/\breseller\b/iu' => '',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/ *([,;:.])/u', '$1', $text) ?? $text;

        return trim($text);
    }

    /**
     * Apply reseller template overrides to outbound customer mail.
     *
     * @param  array<string, mixed>  $templateData
     * @return array{0: Mailable, 1: string, 2: ?string}|false False when template is disabled
     */
    public function applyToCustomerMail(
        User $customer,
        string $eventKey,
        array $templateData,
        Mailable $mailable,
        string $subject,
        ?string $logBody = null,
    ): array|false {
        if ($customer->reseller_id === null) {
            return [$mailable, $subject, $logBody];
        }

        $reseller = $this->brandingResolver->resellerForCustomer($customer);
        if (! $reseller) {
            return [$mailable, $subject, $logBody];
        }

        $resolved = $this->resolve($reseller, $eventKey);
        if (! $resolved) {
            return [$mailable, $subject, $logBody];
        }

        if (! $resolved['enabled']) {
            return false;
        }

        if (! $resolved['is_overridden']) {
            return [$mailable, $subject, $logBody];
        }

        $rendered = $this->render($reseller, $eventKey, array_merge($templateData, [
            'customer' => $customer,
            'customer_name' => $templateData['customer_name'] ?? $customer->name,
            'customer_email' => $templateData['customer_email'] ?? $customer->email,
        ]));

        if (! $rendered) {
            return [$mailable, $subject, $logBody];
        }

        return [
            new TemplatedNotificationMail($rendered['subject'], $rendered['body'], $templateData),
            $rendered['subject'],
            $rendered['body'],
        ];
    }

    /**
     * @param  array{subject: string, body: string, enabled?: bool}  $data
     */
    public function update(User $reseller, string $eventKey, array $data): array
    {
        $default = collect(self::defaultTemplates())->firstWhere('event_key', $eventKey);
        if (! $default) {
            throw new \InvalidArgumentException('Unknown email template: '.$eventKey);
        }

        $settings = $reseller->settings ?? [];
        $templates = $settings[self::SETTINGS_KEY] ?? [];
        $templates[$eventKey] = [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true,
            'updated_at' => now()->toIso8601String(),
        ];
        $settings[self::SETTINGS_KEY] = $templates;
        $reseller->update(['settings' => $settings]);

        Log::info('Reseller email template updated', [
            'reseller_id' => $reseller->id,
            'event_key' => $eventKey,
        ]);

        return $this->listForReseller($reseller->fresh())->firstWhere('event_key', $eventKey);
    }

    public function reset(User $reseller, string $eventKey): array
    {
        $default = collect(self::defaultTemplates())->firstWhere('event_key', $eventKey);
        if (! $default) {
            throw new \InvalidArgumentException('Unknown email template: '.$eventKey);
        }

        $settings = $reseller->settings ?? [];
        $templates = $settings[self::SETTINGS_KEY] ?? [];
        unset($templates[$eventKey]);
        $settings[self::SETTINGS_KEY] = $templates;
        $reseller->update(['settings' => $settings]);

        return $this->listForReseller($reseller->fresh())->firstWhere('event_key', $eventKey);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function overrides(User $reseller): array
    {
        $raw = $reseller->settings[self::SETTINGS_KEY] ?? [];

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function brandingVariables(User $reseller, mixed $customer = null): array
    {
        // Always resolve from the owning reseller — never fall through to platform defaults.
        $branding = $this->brandingResolver->forReseller($reseller);
        $companyName = $reseller->settings['branding']['company_name'] ?? null;
        if (! filled($companyName)) {
            $companyName = $branding['company_name'] ?? $reseller->company ?? $reseller->name ?? 'Your Provider';
        }

        $portalUrl = (string) ($branding['portal_url'] ?? url('/'));

        return [
            'site_name' => (string) $companyName,
            'support_email' => (string) (
                filled($reseller->settings['branding']['support_email'] ?? null)
                    ? $reseller->settings['branding']['support_email']
                    : ($branding['support_email'] ?? $reseller->email ?? '')
            ),
            'portal_url' => $portalUrl,
            'login_url' => $portalUrl !== '' ? $portalUrl : (string) route('login'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function replacePlaceholders(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $text = str_replace('{'.$key.'}', (string) $value, $text);
            }
        }

        return $text;
    }
}
