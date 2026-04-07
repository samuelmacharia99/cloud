<?php

namespace App\Services;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    // Free API endpoint (no authentication required)
    private const API_URL = 'https://api.exchangerate-api.com/v4/latest/';
    private const CACHE_DURATION = 3600; // 1 hour
    private const BASE_CURRENCY = 'KES';

    /**
     * Fetch and update exchange rates from API
     */
    public function updateExchangeRates(): array
    {
        try {
            $cacheKey = "currency_rates_{$this->getBaseCurrency()}";

            // Check cache first
            if (Cache::has($cacheKey)) {
                Log::info('Using cached exchange rates');
                return Cache::get($cacheKey);
            }

            // Fetch from API
            $rates = $this->fetchRatesFromAPI();

            // Cache the rates
            Cache::put($cacheKey, $rates, self::CACHE_DURATION);

            // Update database
            $this->updateDatabaseRates($rates);

            Log::info('Exchange rates updated successfully', ['count' => count($rates)]);

            return $rates;
        } catch (\Exception $e) {
            Log::error('Failed to update exchange rates: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch rates from API
     */
    private function fetchRatesFromAPI(): array
    {
        try {
            $response = Http::timeout(10)->get(self::API_URL . self::BASE_CURRENCY);

            if ($response->failed()) {
                throw new \Exception("API request failed with status {$response->status()}");
            }

            $data = $response->json();

            if (!isset($data['rates']) || !is_array($data['rates'])) {
                throw new \Exception('Invalid API response format');
            }

            return $data['rates'];
        } catch (\Exception $e) {
            Log::error('Currency API error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update currency rates in database
     */
    private function updateDatabaseRates(array $rates): void
    {
        $now = now();

        foreach ($rates as $code => $rate) {
            // Only update currencies we support
            $currency = Currency::where('code', $code)->first();

            if ($currency) {
                $currency->update([
                    'exchange_rate' => $rate,
                    'rate_updated_at' => $now,
                ]);
            }
        }

        // Ensure KES (base currency) always has rate of 1
        $kes = Currency::where('code', 'KES')->first();
        if ($kes) {
            $kes->update([
                'exchange_rate' => 1.0,
                'rate_updated_at' => $now,
            ]);
        }
    }

    /**
     * Get base currency
     */
    private function getBaseCurrency(): string
    {
        return self::BASE_CURRENCY;
    }

    /**
     * Check if rates are stale
     */
    public function areRatesStale(): bool
    {
        $baseCurrency = Currency::getBaseCurrency();

        if (!$baseCurrency || !$baseCurrency->rate_updated_at) {
            return true;
        }

        // Consider rates stale if older than 24 hours
        return $baseCurrency->rate_updated_at->diffInHours(now()) > 24;
    }

    /**
     * Force update rates even if cached
     */
    public function forceUpdateRates(): array
    {
        Cache::forget("currency_rates_{$this->getBaseCurrency()}");
        return $this->updateExchangeRates();
    }

    /**
     * Get current rate for a currency
     */
    public function getRate(string $currencyCode): ?float
    {
        return Currency::where('code', $currencyCode)->value('exchange_rate');
    }

    /**
     * Convert amount between currencies
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        return Currency::convert($amount, $fromCurrency, $toCurrency);
    }
}
