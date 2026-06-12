<?php

namespace App\Services;

use App\Enums\TaxContext;
use App\Models\Setting;
use App\Models\User;

class TaxService
{
    /**
     * @param  array<int, string|bool|null>  $truthy
     */
    public static function isTruthy(mixed $value, array $truthy = ['1', 'true', true]): bool
    {
        return in_array($value, $truthy, true);
    }

    public static function isEnabled(): bool
    {
        return self::isTruthy(Setting::getValue('tax_enabled'));
    }

    public static function isInclusive(): bool
    {
        return self::isTruthy(Setting::getValue('tax_inclusive'));
    }

    public static function rate(): float
    {
        return max(0, (float) Setting::getValue('tax_rate', 0));
    }

    public static function name(): string
    {
        return (string) Setting::getValue('tax_name', 'VAT');
    }

    public static function contextForUser(?User $user): TaxContext
    {
        if ($user === null) {
            return TaxContext::PlatformCustomer;
        }

        if ($user->is_reseller || $user->reseller_id) {
            return TaxContext::ResellerWholesale;
        }

        return TaxContext::PlatformCustomer;
    }

    public static function appliesPlatformTax(?User $user): bool
    {
        return self::contextForUser($user) === TaxContext::PlatformCustomer;
    }

    /**
     * @return array{
     *     subtotal: float,
     *     tax: float,
     *     total: float,
     *     enabled: bool,
     *     inclusive: bool,
     *     rate: float,
     *     name: string,
     *     exempt?: bool
     * }
     */
    public static function calculate(float $amount, int $precision = 2, TaxContext $context = TaxContext::PlatformCustomer): array
    {
        if ($context === TaxContext::ResellerWholesale) {
            return self::exempt($amount, $precision);
        }

        return self::calculateTaxable($amount, $precision);
    }

    public static function calculateForUser(float $amount, ?User $user, int $precision = 2): array
    {
        return self::calculate($amount, $precision, self::contextForUser($user));
    }

    public static function calculateResellerSubscription(float $amount, int $precision = 2): array
    {
        return self::calculate($amount, $precision, TaxContext::ResellerSubscription);
    }

    public static function calculateResellerWholesale(float $amount, int $precision = 2): array
    {
        return self::calculate($amount, $precision, TaxContext::ResellerWholesale);
    }

    /**
     * @return array{
     *     subtotal: float,
     *     tax: float,
     *     total: float,
     *     enabled: bool,
     *     inclusive: bool,
     *     rate: float,
     *     name: string,
     *     exempt: bool
     * }
     */
    public static function exempt(float $amount, int $precision = 2): array
    {
        $amount = round(max(0, $amount), $precision);

        return [
            'subtotal' => $amount,
            'tax' => 0.0,
            'total' => $amount,
            'enabled' => false,
            'inclusive' => self::isInclusive(),
            'rate' => 0.0,
            'name' => self::name(),
            'exempt' => true,
        ];
    }

    /**
     * @return array{
     *     subtotal: float,
     *     tax: float,
     *     total: float,
     *     enabled: bool,
     *     inclusive: bool,
     *     rate: float,
     *     name: string
     * }
     */
    private static function calculateTaxable(float $amount, int $precision = 2): array
    {
        $enabled = self::isEnabled();
        $inclusive = self::isInclusive();
        $rate = self::rate();
        $name = self::name();

        $amount = round(max(0, $amount), $precision);

        if (! $enabled || $rate <= 0) {
            return [
                'subtotal' => $amount,
                'tax' => 0.0,
                'total' => $amount,
                'enabled' => $enabled,
                'inclusive' => $inclusive,
                'rate' => $rate,
                'name' => $name,
            ];
        }

        if ($inclusive) {
            $total = $amount;
            $subtotal = round($total / (1 + ($rate / 100)), $precision);
            $tax = round($total - $subtotal, $precision);

            return [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'enabled' => true,
                'inclusive' => true,
                'rate' => $rate,
                'name' => $name,
            ];
        }

        $subtotal = $amount;
        $tax = round($subtotal * $rate / 100, $precision);
        $total = round($subtotal + $tax, $precision);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'enabled' => true,
            'inclusive' => false,
            'rate' => $rate,
            'name' => $name,
        ];
    }

    /**
     * Calculate tax for a custom rate override (e.g. admin manual invoice).
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    public static function calculateWithRate(float $amount, float $taxRate, int $precision = 2): array
    {
        $amount = round(max(0, $amount), $precision);
        $taxRate = max(0, $taxRate);

        if ($taxRate <= 0) {
            return [
                'subtotal' => $amount,
                'tax' => 0.0,
                'total' => $amount,
            ];
        }

        if (self::isInclusive()) {
            $total = $amount;
            $subtotal = round($total / (1 + ($taxRate / 100)), $precision);
            $tax = round($total - $subtotal, $precision);

            return compact('subtotal', 'tax', 'total');
        }

        $subtotal = $amount;
        $tax = round($subtotal * $taxRate / 100, $precision);
        $total = round($subtotal + $tax, $precision);

        return compact('subtotal', 'tax', 'total');
    }

    public static function calculateWithRateForUser(float $amount, float $taxRate, ?User $user, int $precision = 2): array
    {
        if (self::contextForUser($user) === TaxContext::ResellerWholesale) {
            return self::exempt($amount, $precision);
        }

        return self::calculateWithRate($amount, $taxRate, $precision);
    }
}
