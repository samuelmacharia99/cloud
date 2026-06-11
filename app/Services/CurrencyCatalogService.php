<?php

namespace App\Services;

use App\Models\Currency;
use Database\Seeders\CurrencySeeder;

class CurrencyCatalogService
{
    public function ensure(string $code): Currency
    {
        $code = strtoupper(trim($code));

        $currency = Currency::where('code', $code)->first();
        if ($currency) {
            return $currency;
        }

        if (Currency::count() === 0) {
            (new CurrencySeeder)->run();
        } else {
            $this->provisionCode($code);
        }

        $currency = Currency::where('code', $code)->first();
        if ($currency) {
            return $currency;
        }

        $baseCode = config('currency.base', 'KES');
        if ($code !== $baseCode) {
            return $this->ensure($baseCode);
        }

        return Currency::firstOrCreate(
            ['code' => $baseCode],
            [
                'name' => 'Kenya Shilling',
                'symbol' => 'KES',
                'exchange_rate' => 1.0,
                'is_active' => true,
                'order' => 1,
            ]
        );
    }

    public function ensureBase(): Currency
    {
        return $this->ensure(config('currency.base', 'KES'));
    }

    private function provisionCode(string $code): void
    {
        foreach (CurrencySeeder::catalog() as $row) {
            if ($row['code'] !== $code) {
                continue;
            }

            Currency::firstOrCreate(
                ['code' => $row['code']],
                array_merge($row, [
                    'exchange_rate' => $row['code'] === config('currency.base', 'KES') ? 1.0 : 1.0,
                    'is_active' => true,
                ])
            );

            return;
        }

        Currency::firstOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'symbol' => $code,
                'exchange_rate' => 1.0,
                'is_active' => true,
                'order' => 99,
            ]
        );
    }
}
