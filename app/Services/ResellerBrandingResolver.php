<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class ResellerBrandingResolver
{
    public const CACHE_KEY_PREFIX = 'reseller_by_domain:';

    public function defaults(): array
    {
        return [
            'company_name' => Setting::getValue('company_name', config('app.name', 'Talksasa Cloud')),
            'tagline' => Setting::getValue('site_tagline', ''),
            'custom_domain' => null,
            'logo_url' => Setting::getValue('logo_url'),
            'favicon_url' => null,
            'primary_color' => Setting::getValue('primary_color', '#2563eb'),
            'footer_text' => Setting::getValue('footer_text', ''),
            'support_email' => Setting::getValue('site_email', Setting::getValue('company_email')),
            'support_phone' => Setting::getValue('company_phone'),
            'portal_url' => Setting::getValue('site_url', config('app.url')),
            'reseller_id' => null,
            'is_white_label' => false,
        ];
    }

    public function forReseller(?User $reseller): array
    {
        if (! $reseller || ! $reseller->is_reseller) {
            return $this->defaults();
        }

        $reseller->loadMissing([]);
        $stored = $reseller->settings['branding'] ?? [];

        $branding = array_merge($this->defaults(), [
            'company_name' => $stored['company_name'] ?? $reseller->company ?? $reseller->name,
            'tagline' => $stored['tagline'] ?? '',
            'custom_domain' => $stored['custom_domain'] ?? null,
            'logo_url' => branding_asset_url($stored['logo_url'] ?? null),
            'favicon_url' => branding_asset_url($stored['favicon_url'] ?? null),
            'primary_color' => $stored['primary_color'] ?? '#7c3aed',
            'footer_text' => $stored['footer_text'] ?? '',
            'support_email' => $stored['support_email'] ?? $reseller->email,
            'support_phone' => $stored['support_phone'] ?? $reseller->phone,
            'reseller_id' => $reseller->id,
            'is_white_label' => ! empty($stored['company_name']),
        ]);

        $branding['portal_url'] = $this->portalUrl($reseller, $branding);

        return $branding;
    }

    public function forCustomer(?User $customer): array
    {
        if (! $customer) {
            return $this->defaults();
        }

        if ($customer->is_reseller) {
            return $this->forReseller($customer);
        }

        if ($customer->reseller_id) {
            $reseller = $customer->relationLoaded('reseller')
                ? $customer->reseller
                : User::find($customer->reseller_id);

            return $this->forReseller($reseller);
        }

        return $this->defaults();
    }

    public function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('user.reseller');

        return $this->forCustomer($invoice->user);
    }

    public function resellerForCustomer(?User $customer): ?User
    {
        if (! $customer) {
            return null;
        }

        if ($customer->is_reseller) {
            return $customer;
        }

        if (! $customer->reseller_id) {
            return null;
        }

        return $customer->relationLoaded('reseller')
            ? $customer->reseller
            : User::find($customer->reseller_id);
    }

    public function resolveFromHost(?string $host): ?User
    {
        $host = $this->normalizeHost($host);

        if ($host === '' || $this->isPlatformHost($host)) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY_PREFIX.$host, 300, function () use ($host) {
            return User::query()
                ->where('is_reseller', true)
                ->get()
                ->first(function (User $reseller) use ($host) {
                    $domain = $this->normalizeHost($reseller->settings['branding']['custom_domain'] ?? '');

                    return $domain !== '' && $domain === $host;
                });
        });
    }

    public function portalUrl(?User $reseller, ?array $branding = null): string
    {
        $branding ??= $this->forReseller($reseller);
        $domain = $branding['custom_domain'] ?? null;

        if ($domain) {
            return 'https://'.$this->normalizeHost($domain);
        }

        return Setting::getValue('site_url', config('app.url'));
    }

    public function signedRegistrationUrl(User $reseller): string
    {
        return URL::temporarySignedRoute(
            'register',
            now()->addDays(90),
            ['reseller' => $reseller->id]
        );
    }

    /**
     * @return array<string, array{label: string, ready: bool, hint: string}>
     */
    public function status(User $reseller): array
    {
        $branding = $this->forReseller($reseller);
        $mpesa = $reseller->settings['mpesa'] ?? [];
        $smtp = $reseller->settings['smtp'] ?? [];
        $sms = $reseller->settings['sms'] ?? [];

        return [
            'portal' => [
                'label' => 'Customer portal',
                'ready' => ! empty($branding['company_name']),
                'hint' => 'Company name and logo appear in the customer dashboard.',
            ],
            'auth' => [
                'label' => 'Login & register',
                'ready' => ! empty($branding['custom_domain']) || ! empty($branding['company_name']),
                'hint' => 'Custom domain or invite links show your brand before login.',
            ],
            'email' => [
                'label' => 'Email',
                'ready' => ! empty($smtp['enabled']) && ! empty($smtp['host']) && ! empty($smtp['from_address']),
                'hint' => 'SMTP sends invoices and notifications from your company.',
            ],
            'sms' => [
                'label' => 'SMS',
                'ready' => ! empty($sms['enabled']) && ! empty($sms['api_key']) && ! empty($sms['sender_id']),
                'hint' => 'SMS alerts use your sender ID for managed customers.',
            ],
            'payments' => [
                'label' => 'M-Pesa payments',
                'ready' => ! empty($mpesa['business_shortcode']) && ! empty($mpesa['consumer_key']) && ! empty($mpesa['passkey']),
                'hint' => 'Customer invoice STK push uses your paybill when configured.',
            ],
            'domain' => [
                'label' => 'Custom domain',
                'ready' => ! empty($branding['custom_domain']),
                'hint' => 'Point DNS to this server. SSL is issued automatically in the background.',
            ],
            'ssl' => [
                'label' => 'SSL certificate',
                'ready' => ($reseller->settings['branding']['ssl']['status'] ?? 'none') === 'active',
                'hint' => 'Use Provision SSL on branding settings once DNS points to this server.',
            ],
            'documents' => [
                'label' => 'Invoice PDFs',
                'ready' => ! empty($branding['company_name']),
                'hint' => 'PDF invoices use your company name and logo.',
            ],
        ];
    }

    public function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = rtrim($host, '/');
        $host = preg_replace('#:\d+$#', '', $host) ?? $host;

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    public function isPlatformHost(string $host): bool
    {
        $platformHost = $this->normalizeHost(parse_url(config('app.url'), PHP_URL_HOST) ?: '');

        return $platformHost !== '' && $host === $platformHost;
    }

    public function forgetDomainCache(?string $domain): void
    {
        if ($domain) {
            Cache::forget(self::CACHE_KEY_PREFIX.$this->normalizeHost($domain));
        }
    }
}
