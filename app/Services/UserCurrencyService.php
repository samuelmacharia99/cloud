<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\User;
use App\Support\CountryCurrency;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class UserCurrencyService
{
    public function __construct(
        private CurrencyConversionService $conversionService,
    ) {}

    public function codeFor(?User $user = null): string
    {
        $user ??= Auth::user();

        if ($user?->preferred_currency) {
            return $this->resolveActiveCode($user->preferred_currency);
        }

        if ($user?->country) {
            return $this->resolveActiveCode(CountryCurrency::forCountry($user->country));
        }

        if ($code = session('display_currency')) {
            return $this->resolveActiveCode($code);
        }

        return $this->resolveActiveCode(config('currency.base', 'KES'));
    }

    public function model(?User $user = null): Currency
    {
        $code = $this->codeFor($user);

        return Cache::remember("currency:active:{$code}", 300, function () use ($code) {
            $currency = Currency::where('code', $code)->where('is_active', true)->first()
                ?? Currency::getBaseCurrency();

            if ($currency) {
                return $currency;
            }

            return app(CurrencyCatalogService::class)->ensure($code);
        });
    }

    public function syncFromCountry(User $user, bool $force = false): void
    {
        if (! $force && filled($user->preferred_currency)) {
            return;
        }

        if (blank($user->country)) {
            return;
        }

        $code = $this->resolveActiveCode(CountryCurrency::forCountry($user->country));

        if ($user->preferred_currency !== $code) {
            $user->forceFill(['preferred_currency' => $code])->save();
        }
    }

    public function setPreference(?User $user, string $currencyCode): void
    {
        $code = $this->resolveActiveCode($currencyCode);

        if ($user) {
            $user->forceFill(['preferred_currency' => $code])->save();
        }

        session(['display_currency' => $code]);
    }

    public function convertFromKes(float $amount, ?User $user = null): float
    {
        $currency = $this->model($user);

        if ($currency->code === config('currency.base', 'KES')) {
            return round($amount, $this->decimalsFor($currency->code));
        }

        $this->ensureFreshRates();

        return round($currency->convertFromKES($amount), $this->decimalsFor($currency->code));
    }

    public function convertToKes(float $amount, string $currencyCode): float
    {
        if ($currencyCode === config('currency.base', 'KES')) {
            return round($amount, 2);
        }

        $currency = Currency::where('code', $currencyCode)->first();

        if (! $currency) {
            return round($amount, 2);
        }

        $this->ensureFreshRates();

        return round($currency->convertToKES($amount), 2);
    }

    public function formatKesAmount(float $amountKes, ?User $user = null): string
    {
        $currency = $this->model($user);
        $display = $this->convertFromKes($amountKes, $user);

        return $currency->format($display);
    }

    public function decimalsFor(string $currencyCode): int
    {
        return in_array(strtoupper($currencyCode), config('currency.zero_decimal', []), true) ? 0 : 2;
    }

    public function activeOptions(): array
    {
        return Currency::active()->get()->map(fn (Currency $c) => [
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
        ])->all();
    }

    private function resolveActiveCode(string $code): string
    {
        $code = strtoupper(trim($code));

        $currency = Currency::where('code', $code)->where('is_active', true)->first();

        if ($currency) {
            return $currency->code;
        }

        $base = Currency::getBaseCurrency();

        return $base?->code ?? config('currency.base', 'KES');
    }

    private function ensureFreshRates(): void
    {
        if ($this->conversionService->areRatesStale()) {
            try {
                $this->conversionService->updateExchangeRates();
            } catch (\Throwable) {
                // Use last known rates from the database.
            }
        }
    }
}
