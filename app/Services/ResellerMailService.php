<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

class ResellerMailService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
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

    public function sendToCustomer(User $customer, Mailable $mailable, ?string $subject = null): void
    {
        $branding = $this->brandingResolver->forCustomer($customer);
        $reseller = $this->brandingResolver->resellerForCustomer($customer);

        View::share('emailBranding', $branding);

        $mailer = $this->configureMailer($reseller);

        Mail::mailer($mailer)->to($customer->email)->send($mailable);
    }

    public function sendRaw(User $recipient, string $subject, string $body, ?User $reseller = null): void
    {
        $branding = $this->brandingResolver->forReseller($reseller);
        $mailer = $this->configureMailer($reseller);

        Mail::mailer($mailer)->raw($body, function ($message) use ($recipient, $subject, $branding) {
            $message->to($recipient->email ?? $recipient)
                ->subject($subject)
                ->from(
                    $this->fromAddress($branding, $reseller),
                    $this->fromName($branding, $reseller)
                );
        });
    }

    public function sendTest(User $reseller, string $testEmail): void
    {
        if (! $this->resellerSmtpEnabled($reseller)) {
            throw new \RuntimeException('Reseller SMTP is not enabled or fully configured.');
        }

        $branding = $this->brandingResolver->forReseller($reseller);
        $mailer = $this->configureMailer($reseller);

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
    }

    private function configureMailer(?User $reseller): string
    {
        if ($reseller && $this->resellerSmtpEnabled($reseller)) {
            $smtp = $reseller->settings['smtp'];
            $branding = $this->brandingResolver->forReseller($reseller);

            Config::set('mail.mailers.reseller_smtp', [
                'transport' => 'smtp',
                'host' => $smtp['host'],
                'port' => (int) ($smtp['port'] ?? 587),
                'encryption' => $smtp['encryption'] ?? 'tls',
                'username' => $smtp['username'] ?? null,
                'password' => $smtp['password'] ?? null,
                'timeout' => null,
            ]);

            Config::set('mail.from', [
                'address' => $this->fromAddress($branding, $reseller),
                'name' => $this->fromName($branding, $reseller),
            ]);

            return 'reseller_smtp';
        }

        return config('mail.default', 'smtp');
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
