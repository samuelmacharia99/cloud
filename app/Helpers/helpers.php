<?php

use App\Helpers\CurrencyHelper;
use App\Helpers\CronHelper;
use App\Models\Setting;

/**
 * Generate the dynamic cron command for the current environment
 */
function getCronCommand(?string $outputFile = null): string
{
    return CronHelper::generateCronCommand($outputFile);
}

/**
 * Get all cron command options for different scenarios
 */
function getCronCommandOptions(): array
{
    return CronHelper::getCronCommandOptions();
}

/**
 * Get the selected/default currency for the system
 */
function getSelectedCurrency()
{
    return CurrencyHelper::getSelectedCurrency();
}

/**
 * Get the selected currency code
 */
function getSelectedCurrencyCode(): string
{
    return CurrencyHelper::getSelectedCurrencyCode();
}

/**
 * Get the selected currency symbol
 */
function getSelectedCurrencySymbol(): string
{
    return CurrencyHelper::getSelectedCurrencySymbol();
}

/**
 * Format an amount with the selected currency
 */
function formatPrice($amount, $decimals = 2): string
{
    return CurrencyHelper::formatPrice($amount, $decimals);
}

/**
 * Convert amount from base currency (KES) to selected currency
 */
function convertFromBase($amount): float
{
    return CurrencyHelper::convertFromBase($amount);
}

/**
 * Convert amount from selected currency to base currency (KES)
 */
function convertToBase($amount): float
{
    return CurrencyHelper::convertToBase($amount);
}

/**
 * Check if selected currency is base currency (KES)
 */
function isBaseCurrency(): bool
{
    return CurrencyHelper::isBaseCurrency();
}

/**
 * Get a setting value by key with optional default
 */
function setting(string $key, $default = null)
{
    return Setting::getValue($key, $default);
}

/**
 * Use a same-origin path for uploaded branding assets (avoids SSL hostname errors on custom domains).
 */
function branding_asset_url(?string $url): ?string
{
    if (empty($url)) {
        return null;
    }

    if (str_starts_with($url, '/')) {
        $localPath = public_path(ltrim($url, '/'));

        return is_file($localPath) ? $url : null;
    }

    $path = parse_url($url, PHP_URL_PATH);

    return ($path && str_starts_with($path, '/')) ? $path : $url;
}

/**
 * Format bytes as human-readable size
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
