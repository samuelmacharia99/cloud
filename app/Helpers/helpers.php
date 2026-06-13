<?php

use App\Helpers\CronHelper;
use App\Helpers\CurrencyHelper;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

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

    $path = str_starts_with($url, '/')
        ? $url
        : parse_url($url, PHP_URL_PATH);

    if (! is_string($path) || ! str_starts_with($path, '/')) {
        return $url;
    }

    if (str_starts_with($path, '/storage/')) {
        $relative = ltrim(substr($path, strlen('/storage/')), '/');

        if ($relative !== '' && Storage::disk('public')->exists($relative)) {
            return $path;
        }
    }

    if (is_file(public_path(ltrim($path, '/')))) {
        return $path;
    }

    return null;
}

/**
 * Ensure a domain extension is displayed with a single leading dot (e.g. .co.ke).
 */
function format_domain_extension(?string $extension): string
{
    $extension = trim((string) $extension);

    if ($extension === '') {
        return '';
    }

    return str_starts_with($extension, '.') ? $extension : '.'.$extension;
}

/**
 * Build a full domain name from label + extension without double dots.
 */
function format_domain_name(?string $name, ?string $extension): string
{
    $name = rtrim(trim((string) $name), '.');

    return $name.format_domain_extension($extension);
}

/**
 * Resolve a branding asset URL from settings, with upload-directory fallback.
 */
function branding_asset_url_or_fallback(?string $url, string $type = 'logo'): ?string
{
    $resolved = branding_asset_url($url);

    if ($resolved !== null) {
        return $resolved;
    }

    if (! in_array($type, ['logo', 'favicon'], true)) {
        return null;
    }

    $disk = Storage::disk('public');
    $directory = "branding/{$type}";

    if (! $disk->exists($directory)) {
        return null;
    }

    $files = collect($disk->files($directory))
        ->filter(fn (string $file) => ! str_ends_with($file, '/'))
        ->sortByDesc(fn (string $file) => $disk->lastModified($file))
        ->values();

    $latest = $files->first();

    return $latest ? '/storage/'.$latest : null;
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

    return round($bytes, $precision).' '.$units[$pow];
}
