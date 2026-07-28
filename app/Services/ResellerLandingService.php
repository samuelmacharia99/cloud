<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;

class ResellerLandingService
{
    public const TEMPLATE_LEGACY = 'legacy';

    public const TEMPLATE_MODERN = 'modern';

    public const TEMPLATE_MINIMAL = 'minimal';

    public const TEMPLATE_SHOWCASE = 'showcase';

    /**
     * Built-in storefront templates. Only available ones can be selected;
     * others are shown as "Coming soon" in branding settings.
     *
     * @return array<string, array{label: string, description: string, available: bool}>
     */
    public function templates(): array
    {
        return [
            self::TEMPLATE_LEGACY => [
                'label' => 'Legacy (WHMCS-style)',
                'description' => 'Classic hosting storefront with utility bar, domain search, and plan boxes.',
                'available' => true,
            ],
            self::TEMPLATE_MODERN => [
                'label' => 'Modern',
                'description' => 'Airy marketing layout with large headline, soft hero wash, and rounded plan cards.',
                'available' => true,
            ],
            self::TEMPLATE_MINIMAL => [
                'label' => 'Minimal',
                'description' => 'Focused one-column page — domain search first, compact pricing and hosting rows.',
                'available' => true,
            ],
            self::TEMPLATE_SHOWCASE => [
                'label' => 'Showcase',
                'description' => 'Dark editorial storefront with bold type, TLD table, and highlighted plan cards.',
                'available' => true,
            ],
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     template: string,
     *     hero_headline: string,
     *     hero_subtext: string,
     *     show_domains: bool,
     *     show_hosting: bool
     * }
     */
    public function config(User $reseller): array
    {
        $stored = $reseller->settings['branding'] ?? [];
        $template = (string) ($stored['landing_template'] ?? self::TEMPLATE_LEGACY);
        $templates = $this->templates();

        if (! isset($templates[$template]) || ! ($templates[$template]['available'] ?? false)) {
            $template = self::TEMPLATE_LEGACY;
        }

        return [
            'enabled' => filter_var($stored['landing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'template' => $template,
            'hero_headline' => trim((string) ($stored['landing_hero_headline'] ?? '')),
            'hero_subtext' => trim((string) ($stored['landing_hero_subtext'] ?? '')),
            'show_domains' => filter_var($stored['landing_show_domains'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'show_hosting' => filter_var($stored['landing_show_hosting'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function isEnabled(User $reseller): bool
    {
        return $this->config($reseller)['enabled'] === true;
    }

    /**
     * @return array{
     *     config: array,
     *     branding: array,
     *     extensions: list<array<string, mixed>>,
     *     service_groups: list<array{type: string, label: string, products: list<array<string, mixed>>}>
     * }
     */
    public function storefrontPayload(User $reseller): array
    {
        $config = $this->config($reseller);
        $branding = app(ResellerBrandingResolver::class)->forReseller($reseller);
        $api = app(ResellerPublicApiService::class);

        $extensions = $config['show_domains']
            ? $api->listExtensions($reseller, 1)
            : [];

        $services = $config['show_hosting']
            ? $api->listServices($reseller)
            : [];

        return [
            'config' => $config,
            'branding' => $branding,
            'extensions' => $extensions,
            'service_groups' => $this->groupServices($services),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return list<array{type: string, label: string, products: list<array<string, mixed>>}>
     */
    public function groupServices(array $services): array
    {
        $order = [
            'shared_hosting',
            'container_hosting',
            'email_hosting',
            'vps',
            'dedicated_server',
            'ssl',
        ];

        $grouped = [];
        foreach ($services as $service) {
            $type = (string) ($service['type'] ?? 'other');
            $grouped[$type][] = $service;
        }

        $result = [];
        foreach ($order as $type) {
            if (empty($grouped[$type])) {
                continue;
            }

            $result[] = [
                'type' => $type,
                'label' => $this->storefrontTypeLabel($type),
                'products' => $grouped[$type],
            ];
            unset($grouped[$type]);
        }

        foreach ($grouped as $type => $products) {
            $result[] = [
                'type' => $type,
                'label' => $this->storefrontTypeLabel($type),
                'products' => $products,
            ];
        }

        return $result;
    }

    public function storefrontTypeLabel(string $type): string
    {
        return match ($type) {
            'shared_hosting' => 'Web Hosting',
            'container_hosting' => 'Application Hosting',
            'email_hosting' => 'Email Hosting',
            'vps' => 'VPS Servers',
            'dedicated_server' => 'Dedicated Servers',
            'ssl' => 'SSL Certificates',
            default => Product::typeLabel($type),
        };
    }

    public function viewName(string $template): string
    {
        return 'public.landing.'.$template;
    }
}
