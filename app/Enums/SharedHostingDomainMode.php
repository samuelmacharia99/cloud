<?php

namespace App\Enums;

enum SharedHostingDomainMode: string
{
    case Register = 'register';
    case Existing = 'existing';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Register => 'Register a new domain',
            self::Existing => 'Use an existing domain (update nameservers)',
            self::Transfer => 'Transfer domain to us',
        };
    }
}
