<?php

namespace App\Support;

class CountryCurrency
{
    public static function forCountry(?string $country): string
    {
        if (blank($country)) {
            return config('currency.default', 'USD');
        }

        $normalized = strtoupper(trim($country));

        if (strlen($normalized) === 2 && isset(config('currency.countries')[$normalized])) {
            return config('currency.countries')[$normalized];
        }

        $nameMap = [
            'KENYA' => 'KES',
            'UGANDA' => 'UGX',
            'TANZANIA' => 'TZS',
            'NIGERIA' => 'NGN',
            'SOUTH AFRICA' => 'ZAR',
            'UNITED STATES' => 'USD',
            'UNITED KINGDOM' => 'GBP',
            'GHANA' => 'GHS',
            'RWANDA' => 'RWF',
            'EGYPT' => 'EGP',
        ];

        $upperName = strtoupper(trim($country));

        if (isset($nameMap[$upperName])) {
            return $nameMap[$upperName];
        }

        return config('currency.default', 'USD');
    }

    public static function isKenya(?string $country): bool
    {
        if (blank($country)) {
            return false;
        }

        $normalized = strtoupper(trim($country));

        return in_array($normalized, ['KE', 'KENYA'], true);
    }
}
