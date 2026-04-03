<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending',
            self::Provisioning => 'Provisioning',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Pending => 'blue',
            self::Provisioning => 'cyan',
            self::Suspended => 'red',
            self::Terminated => 'slate',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending => 'info',
            self::Provisioning => 'info',
            self::Suspended => 'danger',
            self::Terminated => 'secondary',
            self::Failed => 'danger',
            self::Cancelled => 'secondary',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Terminated, self::Failed, self::Cancelled]);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->toArray();
    }
}
