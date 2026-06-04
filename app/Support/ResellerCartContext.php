<?php

namespace App\Support;

class ResellerCartContext
{
    public const SESSION_KEY = 'reseller_cart_context';

    public static function mode(): string
    {
        return session(self::SESSION_KEY.'.mode', 'self');
    }

    public static function customerId(): ?int
    {
        $id = session(self::SESSION_KEY.'.customer_id');

        return $id ? (int) $id : null;
    }

    public static function isCustomerMode(): bool
    {
        return self::mode() === 'customer' && self::customerId() !== null;
    }

    public static function setSelf(): void
    {
        session([self::SESSION_KEY => ['mode' => 'self', 'customer_id' => null]]);
    }

    public static function setCustomer(int $customerId): void
    {
        session([self::SESSION_KEY => ['mode' => 'customer', 'customer_id' => $customerId]]);
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array{mode: string, customer_id: ?int, customer_name: ?string}
     */
    public static function summary(): array
    {
        return [
            'mode' => self::mode(),
            'customer_id' => self::customerId(),
            'customer_name' => session(self::SESSION_KEY.'.customer_name'),
        ];
    }

    public static function setCustomerName(?string $name): void
    {
        session([self::SESSION_KEY.'.customer_name' => $name]);
    }
}
