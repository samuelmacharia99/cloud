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

            $currency = Currency::firstOrCreate(
                ['code' => $row['code']],
                array_merge($row, [
                    'exchange_rate' => $row['code'] === config('currency.base', 'KES') ? 1.0 : 1.0,
                    'is_active' => true,
                ])
            );

            $this->refreshRateIfNeeded($currency);

            return;
        }

        $currency = Currency::firstOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'symbol' => $code,
                'exchange_rate' => 1.0,
                'is_active' => true,
                'order' => 99,
            ]
        );

        $this->refreshRateIfNeeded($currency);
    }

    private function refreshRateIfNeeded(Currency $currency): void
    {
        if ($currency->code === config('currency.base', 'KES')) {
            return;
        }

        $conversion = app(CurrencyConversionService::class);

        try {
            if ($conversion->areRatesStale() || (float) $currency->exchange_rate <= 0 || (float) $currency->exchange_rate === 1.0) {
                $conversion->forceUpdateRates();
            }
        } catch (\Throwable) {
            // Billing validation will surface unusable rates.
        }
    }
}
