<?php

use App\Helpers\CurrencyHelper;
use App\Helpers\CronHelper;

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
