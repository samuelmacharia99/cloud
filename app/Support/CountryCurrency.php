<?php

namespace App\Support;

class CountryCurrency
{
    public static function forCountry(?string $country): string
    {
        if (blank($country)) {
            return config('currency.default', 'USD');
        }

        $code = Countries::normalize($country) ?? strtoupper(trim($country));

        if (isset(config('currency.countries')[$code])) {
            return config('currency.countries')[$code];
        }

        return config('currency.default', 'USD');
    }

    public static function isKenya(?string $country): bool
    {
        $code = Countries::normalize($country);

        return $code === 'KE';
    }
}
