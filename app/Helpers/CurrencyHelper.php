<?php

namespace App\Helpers;

use App\Models\Currency;
use App\Models\Setting;
use App\Models\User;
use App\Services\UserCurrencyService;

class CurrencyHelper
{
    public static function usesCustomerCurrency(): bool
    {
        if (! auth()->check()) {
            return true;
        }

        $user = auth()->user();

        return ! $user->is_admin;
    }

    /**
     * Get the display currency for the current context.
     */
    public static function getSelectedCurrency(?User $user = null): ?Currency
    {
        if (self::usesCustomerCurrency()) {
            return app(UserCurrencyService::class)->model($user);
        }

        $code = Setting::getValue('currency', config('currency.base', 'KES'));

        return Currency::where('code', $code)->where('is_active', true)->first();
    }

    public static function getSelectedCurrencyCode(?User $user = null): string
    {
        if (self::usesCustomerCurrency()) {
            return app(UserCurrencyService::class)->codeFor($user);
        }

        return Setting::getValue('currency', config('currency.base', 'KES'));
    }

    public static function getSelectedCurrencySymbol(?User $user = null): string
    {
        $currency = self::getSelectedCurrency($user);

        return $currency?->symbol ?? Setting::getValue('currency_symbol', 'KES');
    }

    public static function formatPrice($amount, $decimals = 2, ?User $user = null): string
    {
        $currency = self::getSelectedCurrency($user);
        $symbol = $currency?->symbol ?? Setting::getValue('currency_symbol', 'KES');
        $formatted = number_format($amount, $decimals);

        return "{$symbol} {$formatted}";
    }

    public static function convertFromBase($amount, ?User $user = null): float
    {
        if (self::usesCustomerCurrency()) {
            return app(UserCurrencyService::class)->convertFromKes((float) $amount, $user);
        }

        $currency = self::getSelectedCurrency($user);
        if (! $currency) {
            return (float) $amount;
        }

        return $currency->convertFromKES($amount);
    }

    public static function convertToBase($amount, ?User $user = null): float
    {
        $currency = self::getSelectedCurrency($user);
        if (! $currency) {
            return (float) $amount;
        }

        return $currency->convertToKES($amount);
    }

    public static function isBaseCurrency(?User $user = null): bool
    {
        return self::getSelectedCurrencyCode($user) === config('currency.base', 'KES');
    }
}
