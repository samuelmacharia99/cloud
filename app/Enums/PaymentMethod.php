<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Mpesa = 'mpesa';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-PESA',
            self::Card => 'Credit/Debit Card',
            self::BankTransfer => 'Bank Transfer',
            self::Wallet => 'Wallet',
            self::Manual => 'Manual Entry',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Mpesa => 'phone',
            self::Card => 'credit-card',
            self::BankTransfer => 'building-2',
            self::Wallet => 'wallet',
            self::Manual => 'check',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Mpesa => 'green',
            self::Card => 'blue',
            self::BankTransfer => 'slate',
            self::Wallet => 'purple',
            self::Manual => 'amber',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->toArray();
    }
}
