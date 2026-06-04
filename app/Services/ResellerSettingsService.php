<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class ResellerSettingsService
{
    private const SETTINGS_KEY = 'settings';

    public function getMpesaSettings(User $user): array
    {
        return $this->getSettings($user, 'mpesa', [
            'business_shortcode' => '',
            'consumer_key' => '',
            'consumer_secret' => '',
            'passkey' => '',
            'callback_url' => '',
            'timeout_url' => '',
            'urls_registered_at' => null,
        ]);
    }

    public function updateMpesaSettings(User $user, array $data): void
    {
        $settings = $user->settings ?? [];
        $settings['mpesa'] = [
            'business_shortcode' => $data['mpesa_business_shortcode'],
            'consumer_key' => $data['mpesa_consumer_key'],
            'consumer_secret' => $data['mpesa_consumer_secret'],
            'passkey' => $data['mpesa_passkey'],
            'callback_url' => $data['mpesa_callback_url'] ?? null,
            'timeout_url' => $data['mpesa_timeout_url'] ?? null,
            'updated_at' => now(),
        ];

        $user->update([self::SETTINGS_KEY => $settings]);

        Log::info('Reseller M-Pesa settings updated', [
            'reseller_id' => $user->id,
            'shortcode' => $data['mpesa_business_shortcode'],
        ]);
    }

    public function registerMpesaUrls(User $user, array $data): void
    {
        $settings = $user->settings ?? [];
        $mpesaSettings = $settings['mpesa'] ?? [];

        $mpesaSettings['callback_url'] = $data['callback_url'];
        $mpesaSettings['timeout_url'] = $data['timeout_url'];
        $mpesaSettings['urls_registered_at'] = now();

        $settings['mpesa'] = $mpesaSettings;
        $user->update([self::SETTINGS_KEY => $settings]);

        Log::info('M-Pesa URLs registered for reseller', [
            'reseller_id' => $user->id,
            'callback_url' => $data['callback_url'],
        ]);
    }

    public function getSmsSettings(User $user): array
    {
        return $this->getSettings($user, 'sms', [
            'api_key' => '',
            'sender_id' => '',
            'enabled' => false,
            'updated_at' => null,
        ]);
    }

    public function updateSmsSettings(User $user, array $data): void
    {
        $settings = $user->settings ?? [];
        $settings['sms'] = [
            'api_key' => $data['sms_api_key'],
            'sender_id' => $data['sms_sender_id'],
            'enabled' => (bool) $data['sms_enabled'] ?? false,
            'updated_at' => now(),
        ];

        $user->update([self::SETTINGS_KEY => $settings]);

        Log::info('Reseller SMS settings updated', [
            'reseller_id' => $user->id,
            'sender_id' => $data['sms_sender_id'],
            'enabled' => $settings['sms']['enabled'],
        ]);
    }

    public function getSmtpSettings(User $user): array
    {
        return $this->getSettings($user, 'smtp', [
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_address' => '',
            'from_name' => '',
            'enabled' => false,
            'updated_at' => null,
        ]);
    }

    public function updateSmtpSettings(User $user, array $data): void
    {
        $settings = $user->settings ?? [];
        $settings['smtp'] = [
            'host' => $data['smtp_host'],
            'port' => (int) $data['smtp_port'],
            'username' => $data['smtp_username'],
            'password' => $data['smtp_password'],
            'encryption' => $data['smtp_encryption'],
            'from_address' => $data['smtp_from_address'],
            'from_name' => $data['smtp_from_name'],
            'enabled' => (bool) $data['smtp_enabled'] ?? false,
            'updated_at' => now(),
        ];

        $user->update([self::SETTINGS_KEY => $settings]);

        Log::info('Reseller SMTP settings updated', [
            'reseller_id' => $user->id,
            'host' => $data['smtp_host'],
            'enabled' => $settings['smtp']['enabled'],
        ]);
    }

    public function getBrandingSettings(User $user): array
    {
        $branding = $this->getSettings($user, 'branding', [
            'company_name' => '',
            'tagline' => '',
            'custom_domain' => null,
            'logo_url' => null,
            'logo_path' => null,
            'favicon_url' => null,
            'favicon_path' => null,
            'primary_color' => '#7c3aed',
            'footer_text' => '',
            'support_email' => '',
            'support_phone' => '',
            'ssl' => [],
            'updated_at' => null,
        ]);

        $sslStatus = $branding['ssl']['status'] ?? 'none';
        if (in_array($sslStatus, ['failed', 'pending', 'provisioning'], true)) {
            $branding['ssl'] = app(ResellerSslService::class)->sslStatusForCliManaged(
                $branding['custom_domain'] ?? null
            );
            $this->persistBrandingSslState($user, $branding);
        }

        return $branding;
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function persistBrandingSslState(User $user, array $branding): void
    {
        $settings = $user->settings ?? [];
        $settings['branding'] = array_merge($settings['branding'] ?? [], [
            'ssl' => $branding['ssl'],
        ]);
        $user->update([self::SETTINGS_KEY => $settings]);
    }

    public function updateBrandingSettings(User $user, array $data): void
    {
        $settings = $user->settings ?? [];
        $currentBranding = $settings['branding'] ?? [];
        $previousDomain = $currentBranding['custom_domain'] ?? null;
        $newDomain = $data['custom_domain'] ?? null;

        $settings['branding'] = array_merge($currentBranding, [
            'company_name' => $data['company_name'],
            'tagline' => $data['tagline'] ?? '',
            'custom_domain' => $newDomain,
            'primary_color' => $data['primary_color'] ?? '#7c3aed',
            'footer_text' => $data['footer_text'] ?? '',
            'support_email' => $data['support_email'] ?? '',
            'support_phone' => $data['support_phone'] ?? '',
            'updated_at' => now(),
        ]);

        if ($newDomain !== $previousDomain) {
            $settings['branding']['ssl'] = app(ResellerSslService::class)->sslStatusForCliManaged($newDomain);

            if ($previousDomain) {
                app(ResellerBrandingResolver::class)->forgetDomainCache($previousDomain);
            }
        }

        $user->update([self::SETTINGS_KEY => $settings]);

        Log::info('Reseller branding settings updated', [
            'reseller_id' => $user->id,
            'company_name' => $data['company_name'],
            'custom_domain' => $newDomain,
        ]);
    }

    private function getSettings(User $user, string $key, array $defaults): array
    {
        $settings = $user->settings ?? [];

        return array_merge($defaults, $settings[$key] ?? []);
    }
}
