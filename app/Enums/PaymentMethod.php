<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Mpesa = 'mpesa';
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case BankTransfer = 'bank_transfer';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-PESA',
            self::Stripe => 'Stripe (Card)',
            self::PayPal => 'PayPal',
            self::BankTransfer => 'Bank Transfer',
            self::Manual => 'Manual Entry',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Mpesa => 'phone',
            self::Stripe => 'credit-card',
            self::PayPal => 'globe',
            self::BankTransfer => 'building-2',
            self::Manual => 'check',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Mpesa => 'green',
            self::Stripe => 'purple',
            self::PayPal => 'blue',
            self::BankTransfer => 'slate',
            self::Manual => 'amber',
        };
    }

    public function isOnline(): bool
    {
        return in_array($this, [self::Mpesa, self::Stripe, self::PayPal]);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->toArray();
    }

    public static function onlineGateways(): array
    {
        return [
            self::Mpesa->value => self::Mpesa->label(),
            self::Stripe->value => self::Stripe->label(),
            self::PayPal->value => self::PayPal->label(),
        ];
    }
}
