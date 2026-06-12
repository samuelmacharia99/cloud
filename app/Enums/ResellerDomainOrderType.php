<?php

namespace App\Enums;

enum ResellerDomainOrderType: string
{
    case Registration = 'registration';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registration',
            self::Transfer => 'Transfer',
        };
    }
}
