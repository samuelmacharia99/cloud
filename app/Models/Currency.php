<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exchange_rate',
        'is_active',
        'rate_updated_at',
        'order',
    ];

    protected $casts = [
        'exchange_rate' => 'float',
        'is_active' => 'boolean',
        'rate_updated_at' => 'datetime',
    ];

    /**
     * Scope: Get only active currencies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    /**
     * Get the base currency (Kenya Shilling)
     */
    public static function getBaseCurrency()
    {
        return self::where('code', 'KES')->first();
    }

    /**
     * Convert amount from KES to this currency
     */
    public function convertFromKES($amount): float
    {
        return $amount * $this->exchange_rate;
    }

    /**
     * Convert amount from this currency to KES
     */
    public function convertToKES($amount): float
    {
        return $amount / $this->exchange_rate;
    }

    /**
     * Convert between two currencies
     */
    public static function convert($amount, $fromCurrencyCode, $toCurrencyCode): float
    {
        if ($fromCurrencyCode === $toCurrencyCode) {
            return $amount;
        }

        $fromCurrency = self::where('code', $fromCurrencyCode)->firstOrFail();
        $toCurrency = self::where('code', $toCurrencyCode)->firstOrFail();

        // Convert to KES first, then to target currency
        $inKES = $fromCurrency->convertToKES($amount);
        return $toCurrency->convertFromKES($inKES);
    }

    /**
     * Format amount with currency symbol
     */
    public function format($amount): string
    {
        $formatted = number_format($amount, 2);
        return "{$this->symbol} {$formatted}";
    }
}
