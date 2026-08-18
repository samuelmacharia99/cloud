<?php

namespace App\Enums;

enum RegistrarDriver: string
{
    case Manual = 'manual';
    case Openprovider = 'openprovider';
    case Cosmotown = 'cosmotown';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual / Internal',
            self::Openprovider => 'Openprovider',
            self::Cosmotown => 'Cosmotown',
            self::Custom => 'Registrar API (generic)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manual => 'No API — domains are fulfilled manually by admin.',
            self::Openprovider => 'Wholesale domains via Openprovider REST API (v1beta).',
            self::Cosmotown => 'Wholesale domains via Cosmotown Reseller API (register, renew, transfer, nameservers, EPP). Whitelist this server IP.',
            self::Custom => 'Connect to a generic registrar API (configure credentials below).',
        };
    }

    /**
     * @return list<array{key: string, label: string, type: string, placeholder?: string, help?: string}>
     */
    public function configFields(): array
    {
        return match ($this) {
            self::Manual => [],
            self::Openprovider => [
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'placeholder' => 'Openprovider reseller username'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                ['key' => 'login_ip', 'label' => 'Login IP', 'type' => 'text', 'placeholder' => '0.0.0.0', 'help' => 'IP sent at login. Use 0.0.0.0 to allow any IP.'],
                ['key' => 'api_base_url', 'label' => 'API base URL (optional)', 'type' => 'url', 'placeholder' => 'Leave blank to use environment default'],
                ['key' => 'owner_handle', 'label' => 'Owner handle', 'type' => 'text', 'placeholder' => 'XX123456-XX', 'help' => 'Platform default owner contact at Openprovider.'],
                ['key' => 'admin_handle', 'label' => 'Admin handle', 'type' => 'text', 'help' => 'Optional — defaults to owner handle.'],
                ['key' => 'tech_handle', 'label' => 'Tech handle', 'type' => 'text', 'help' => 'Optional — defaults to owner handle.'],
                ['key' => 'billing_handle', 'label' => 'Billing handle', 'type' => 'text', 'help' => 'Optional — defaults to owner handle.'],
            ],
            self::Cosmotown => [
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'help' => 'X-API-TOKEN from Cosmotown Reseller API settings. Whitelist this server IP.'],
                ['key' => 'api_secret', 'label' => 'API secret (optional)', 'type' => 'password', 'help' => 'Optional — Cosmotown docs mention a secret for production keys.'],
                ['key' => 'api_base_url', 'label' => 'API base URL (optional)', 'type' => 'url', 'placeholder' => 'Leave blank for sandbox/production default', 'help' => 'Production default is https://www.cosmotown.com/v1/. Leave blank unless Cosmotown gave you a different host.'],
                ['key' => 'coupon_id', 'label' => 'Coupon ID (optional)', 'type' => 'text', 'help' => 'Passed on register when Cosmotown issued you a reseller coupon.'],
                ['key' => 'contact_first_name', 'label' => 'Contact first name', 'type' => 'text', 'help' => 'Default contact used for /v1/reseller/contactinfo (all roles).'],
                ['key' => 'contact_last_name', 'label' => 'Contact last name', 'type' => 'text'],
                ['key' => 'contact_email', 'label' => 'Contact email', 'type' => 'text'],
                ['key' => 'contact_phone', 'label' => 'Contact phone', 'type' => 'text', 'placeholder' => '+2547…'],
                ['key' => 'contact_company', 'label' => 'Contact company', 'type' => 'text'],
                ['key' => 'contact_address1', 'label' => 'Contact address', 'type' => 'text'],
                ['key' => 'contact_city', 'label' => 'Contact city', 'type' => 'text'],
                ['key' => 'contact_state', 'label' => 'Contact state / region', 'type' => 'text'],
                ['key' => 'contact_zip', 'label' => 'Contact postal code', 'type' => 'text'],
                ['key' => 'contact_country', 'label' => 'Contact country', 'type' => 'text', 'placeholder' => 'KE', 'help' => 'ISO country code (e.g. KE, US).'],
            ],
            self::Custom => [
                ['key' => 'api_url', 'label' => 'API base URL', 'type' => 'url', 'placeholder' => 'https://api.registrar.example/v1'],
                ['key' => 'api_key', 'label' => 'API key / client ID', 'type' => 'text'],
                ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'password'],
                ['key' => 'reseller_id', 'label' => 'Reseller / account ID', 'type' => 'text'],
                ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
                ['key' => 'contact_id', 'label' => 'Default contact ID', 'type' => 'text', 'help' => 'Optional default registrant contact at the registrar.'],
                ['key' => 'nameservers', 'label' => 'Default nameservers', 'type' => 'textarea', 'help' => 'One nameserver per line (optional).'],
            ],
        };
    }
}
