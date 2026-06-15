<?php

namespace App\Enums;

enum TicketHandledBy: string
{
    case Platform = 'platform';
    case Reseller = 'reseller';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform support',
            self::Reseller => 'Your provider',
        };
    }
}
