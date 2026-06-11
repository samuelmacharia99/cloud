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
        $symbol ??= Currency::where('code', $currencyCode)->value('symbol') ?? $currencyCode;

        return trim($symbol.' '.number_format($amount, $decimals));
    }
}
