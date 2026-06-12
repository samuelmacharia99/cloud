<?php

namespace App\Enums;

enum RegistrarDriver: string
{
    case Manual = 'manual';
    case Openprovider = 'openprovider';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual / Internal',
            self::Openprovider => 'Openprovider',
            self::Custom => 'Registrar API (generic)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manual => 'No API — domains are fulfilled manually by admin.',
            self::Openprovider => 'Wholesale domains via Openprovider REST API (v1beta).',
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
