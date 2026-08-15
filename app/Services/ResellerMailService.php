<?php

namespace App\Services;

use App\Mail\GenericNotificationMail;
use App\Mail\TemplatedNotificationMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class ResellerMailService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
        private ResellerEmailTemplateService $emailTemplates,
    ) {}

    public function isConfigured(?User $reseller = null): bool
    {
        if ($reseller && $this->resellerSmtpEnabled($reseller)) {
            return true;
        }

        return ! empty(Setting::getValue('smtp_host', ''));
    }

    public function resellerSmtpEnabled(User $reseller): bool
    {
        $smtp = $reseller->settings['smtp'] ?? [];

        return ! empty($smtp['enabled'])
            && ! empty($smtp['host'])
            && ! empty($smtp['from_address']);
    }

    /**
     * @param  array<string, mixed>  $templateData
     */
    public function sendToCustomer(
        User $customer,
        Mailable $mailable,
        ?string $subject = null,
        ?string $templateEventKey = null,
        array $templateData = [],
    ): void {
        if ($templateEventKey !== null) {
            $applied = $this->emailTemplates->applyToCustomerMail(
                $customer,
                $templateEventKey,
                $templateData,
                $mailable,
                $subject ?? ($mailable->envelope()->subject ?? 'Notification'),
                null,
            );

            if ($applied === false) {
                return;
            }

            [$mailable] = $applied;
        }

        $mailable = $this->scrubCustomerMailable($mailable);

        $reseller = $customer->reseller_id
            ? $this->brandingResolver->resellerForCustomer($customer)
            : null;
        $branding = $reseller
            ? $this->brandingResolver->forCustomer($customer)
            : $this->brandingResolver->forReseller(null);

        if ($customer->reseller_id !== null && ($reseller === null || ! $this->resellerSmtpEnabled($reseller))) {
            throw new \RuntimeException('SMTP must be enabled under Settings → Email before customer emails can send.');
        }

        $this->sendMailable($customer, $mailable, $branding, $reseller);
    }

    /**
     * Compatibility alias: managed-customer mail never falls back to platform SMTP.
     */
    public function sendBrandedWithPlatformFallback(User $customer, Mailable $mailable): void
    {
        $this->sendToCustomer($customer, $mailable);
    }

    public function sendRaw(User $recipient, string $subject, string $body, ?User $reseller = null): void
    {
        $branding = $this->brandingResolver->forReseller($reseller);
        $mailer = $this->configureMailer($reseller);

        try {
            Mail::mailer($mailer)->raw($body, function ($message) use ($recipient, $subject, $branding, $reseller) {
                $message->to($recipient->email ?? $recipient)
                    ->subject($subject)
                    ->from(
                        $this->fromAddress($branding, $reseller),
                        $this->fromName($branding, $reseller)
                    );
            });
        } finally {
            $this->releaseMailer($mailer, $reseller);
        }
    }

    public function sendTest(User $reseller, string $testEmail): void
    {
        if (! $this->resellerSmtpEnabled($reseller)) {
            throw new \RuntimeException('SMTP is not enabled or fully configured.');
        }

        $branding = $this->brandingResolver->forReseller($reseller);
        $mailer = $this->configureMailer($reseller);

        try {
            Mail::mailer($mailer)->raw(
                "This is a test email from {$branding['company_name']}.\n\nYour SMTP configuration is working correctly.",
                function ($message) use ($testEmail, $branding, $reseller) {
                    $message->to($testEmail)
                        ->subject($branding['company_name'].' — SMTP test')
                        ->from(
                            $this->fromAddress($branding, $reseller),
                            $this->fromName($branding, $reseller)
                        );
                }
            );
        } finally {
            $this->releaseMailer($mailer, $reseller);
        }
    }

    private function scrubCustomerMailable(Mailable $mailable): Mailable
    {
        if ($mailable instanceof TemplatedNotificationMail) {
            $mailable->mailSubject = $this->emailTemplates->scrubCustomerFacingCopy($mailable->mailSubject);
            $mailable->bodyText = $this->emailTemplates->scrubCustomerFacingCopy($mailable->bodyText);
        }

        if ($mailable instanceof GenericNotificationMail) {
            $mailable->mailSubject = $this->emailTemplates->scrubCustomerFacingCopy($mailable->mailSubject);
            $mailable->heading = $this->emailTemplates->scrubCustomerFacingCopy($mailable->heading);
            $mailable->body = $this->emailTemplates->scrubCustomerFacingCopy($mailable->body);
        }

        return $mailable;
    }

    private function sendMailable(User $customer, Mailable $mailable, array $branding, ?User $reseller): void
    {
        $mailer = $this->configureMailer($reseller);
        $mailable->with(['emailBranding' => $branding]);
        $mailable->from(
            $this->fromAddress($branding, $reseller),
            $this->fromName($branding, $reseller),
        );

        try {
            // Rendering context and sender are attached to this message; neither is
            // process-global state in queue workers or Octane.
            Mail::mailer($mailer)->to($customer->email)->sendNow($mailable);
        } finally {
            $this->releaseMailer($mailer, $reseller);
        }
    }

    private function configureMailer(?User $reseller): string
    {
        if ($reseller && $this->resellerSmtpEnabled($reseller)) {
            $smtp = $reseller->settings['smtp'];
            $mailer = 'reseller_smtp_'.$reseller->getKey();

            Config::set("mail.mailers.{$mailer}", [
                'transport' => 'smtp',
                'host' => $smtp['host'],
                'port' => (int) ($smtp['port'] ?? 587),
                'encryption' => ($smtp['encryption'] ?? 'tls') === '' ? null : ($smtp['encryption'] ?? 'tls'),
                'username' => $smtp['username'] ?? null,
                'password' => $smtp['password'] ?? null,
                'timeout' => null,
            ]);

            Mail::purge($mailer);

            return $mailer;
        }

        return config('mail.default', 'smtp');
    }

    private function releaseMailer(string $mailer, ?User $reseller): void
    {
        if (! $reseller) {
            return;
        }

        Mail::purge($mailer);
        Config::set("mail.mailers.{$mailer}", null);
    }

    private function fromAddress(array $branding, ?User $reseller): string
    {
        if ($reseller && ! empty($reseller->settings['smtp']['from_address'])) {
            return $reseller->settings['smtp']['from_address'];
        }

        return Setting::getValue('mail_from_address', config('mail.from.address'));
    }

    private function fromName(array $branding, ?User $reseller): string
    {
        if ($reseller && ! empty($reseller->settings['smtp']['from_name'])) {
            return $reseller->settings['smtp']['from_name'];
        }

        return $branding['company_name'] ?? config('mail.from.name');
    }
}
