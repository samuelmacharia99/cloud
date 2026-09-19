<?php

namespace App\Support;

use App\Models\Currency;

class CurrencyFormatter
{
    public static function toMinorUnits(float $amount, string $currencyCode): int
    {
        $multiplier = in_array(strtoupper($currencyCode), config('currency.zero_decimal', []), true) ? 1 : 100;

        return (int) round($amount * $multiplier);
    }

    public static function format(float $amount, string $currencyCode, ?string $symbol = null): string
    {
        $decimals = in_array(strtoupper($currencyCode), config('currency.zero_decimal', []), true) ? 0 : 2;
        $symbol ??= self::symbolFor($currencyCode);

        return trim($symbol.' '.number_format($amount, $decimals));
    }

    /**
     * The symbol for a currency, looked up once per request.
     *
     * Formatting happens per row in invoice and payment tables, and this used to
     * be a query every time. Memoised on the container rather than in a static,
     * so it is rebuilt with the application on each request and each test
     * instead of outliving a currency whose symbol changed.
     */
    public static function symbol(string $currencyCode): string
    {
        return self::symbolFor($currencyCode);
    }

    private static function symbolFor(string $currencyCode): string
    {
        $key = 'currency.symbol.'.strtoupper($currencyCode);

        if (app()->bound($key)) {
            return (string) app()->make($key);
        }

        $symbol = (string) (Currency::where('code', $currencyCode)->value('symbol') ?? $currencyCode);
        app()->instance($key, $symbol);

        return $symbol;
    }
}
