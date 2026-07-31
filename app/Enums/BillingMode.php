<?php

namespace App\Enums;

enum BillingMode: string
{
    case Package = 'package';
    case Usage = 'usage';

    public function label(): string
    {
        return match ($this) {
            self::Package => 'Fixed package',
            self::Usage => 'Usage (floor + meters)',
        };
    }

    public function isUsage(): bool
    {
        return $this === self::Usage;
    }
}
