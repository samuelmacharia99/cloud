<?php

namespace App\Support;

use App\Models\User;
use App\Services\ResellerScopeService;

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

    /**
     * Resolve the customer for whitelabel checkout from cart mode or renewal line items.
     *
     * @param  array<string, array<string, mixed>>  $cart
     */
    public static function resolveCheckoutCustomer(User $reseller, array $cart, ResellerScopeService $scope): ?User
    {
        if (self::isCustomerMode()) {
            $customerId = self::customerId();
            if ($customerId) {
                $customer = User::find($customerId);
                if ($customer && $scope->ownsCustomer($reseller, $customer)) {
                    return $customer;
                }

                self::setSelf();
            }
        }

        $billingCustomerIds = [];
        foreach ($cart as $item) {
            if (($item['type'] ?? '') !== 'domain_renewal') {
                continue;
            }

            if (! empty($item['billing_customer_id'])) {
                $billingCustomerIds[] = (int) $item['billing_customer_id'];
            }
        }

        $billingCustomerIds = array_values(array_unique($billingCustomerIds));
        if (count($billingCustomerIds) !== 1) {
            return null;
        }

        $customer = User::find($billingCustomerIds[0]);

        return ($customer && $scope->ownsCustomer($reseller, $customer)) ? $customer : null;
    }
}
