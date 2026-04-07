<?php

namespace App\Console\Commands;

use App\Services\CurrencyConversionService;

class UpdateExchangeRatesCommand extends BaseCronCommand
{
    protected $signature = 'cron:update-exchange-rates';
    protected $description = 'Update currency exchange rates from global API';

    protected function handleCron(): string
    {
        try {
            $service = new CurrencyConversionService();
            $rates = $service->updateExchangeRates();

            return "Updated exchange rates for " . count($rates) . " currencies";
        } catch (\Exception $e) {
            return "Failed to update exchange rates: " . $e->getMessage();
        }
    }
}
