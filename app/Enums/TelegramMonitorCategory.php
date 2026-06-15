<?php

namespace App\Enums;

enum TelegramMonitorCategory: string
{
    case Errors = 'errors';
    case Payments = 'payments';
    case Services = 'services';
    case Orders = 'orders';
    case Registrations = 'registrations';
    case Tickets = 'tickets';
    case Resellers = 'resellers';
    case System = 'system';

    public function settingKey(): string
    {
        return 'telegram_monitor_'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Errors => 'Application errors (logs)',
            self::Payments => 'Payments & invoices',
            self::Services => 'Service suspend / unsuspend / lifecycle',
            self::Orders => 'New orders & checkouts',
            self::Registrations => 'Customer & reseller signups',
            self::Tickets => 'Support tickets',
            self::Resellers => 'Reseller wallet & enforcement',
            self::System => 'Cron, nodes & containers',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Errors => '🚨',
            self::Payments => '💳',
            self::Services => '⚙️',
            self::Orders => '🛒',
            self::Registrations => '👤',
            self::Tickets => '🎫',
            self::Resellers => '🏢',
            self::System => '🖥️',
        };
    }
}
