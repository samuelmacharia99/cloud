<?php

namespace App\Helpers;

use App\Models\Currency;
use App\Models\Setting;

class CurrencyHelper
{
    /**
     * Get the selected/default currency for the system
     */
    public static function getSelectedCurrency(): ?Currency
    {
        $code = Setting::getValue('currency', 'KES');
        return Currency::where('code', $code)->where('is_active', true)->first();
    }

    /**
     * Get the selected currency code
     */
    public static function getSelectedCurrencyCode(): string
    {
        return Setting::getValue('currency', 'KES');
    }

    /**
     * Get the selected currency symbol
     */
    public static function getSelectedCurrencySymbol(): string
    {
        $currency = self::getSelectedCurrency();
        return $currency?->symbol ?? Setting::getValue('currency_symbol', 'KES');
    }

    /**
     * Format an amount in the selected currency
     */
    public static function formatPrice($amount, $decimals = 2): string
    {
        $currency = self::getSelectedCurrency();
        $symbol = $currency?->symbol ?? Setting::getValue('currency_symbol', 'KES');
        $formatted = number_format($amount, $decimals);
        return "{$symbol} {$formatted}";
    }

    /**
     * Convert amount from base currency (KES) to selected currency
     */
    public static function convertFromBase($amount): float
    {
        $currency = self::getSelectedCurrency();
        if (!$currency) {
            return $amount;
        }
        return $currency->convertFromKES($amount);
    }

    /**
     * Convert amount from selected currency to base currency (KES)
     */
    public static function convertToBase($amount): float
    {
        $currency = self::getSelectedCurrency();
        if (!$currency) {
            return $amount;
        }
        return $currency->convertToKES($amount);
    }

    /**
     * Check if selected currency is base currency (KES)
     */
    public static function isBaseCurrency(): bool
    {
        return self::getSelectedCurrencyCode() === 'KES';
    }
}
