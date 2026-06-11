<?php

namespace App\Services\Billing;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\CurrencyConversionService;
use App\Services\UserCurrencyService;

class InvoiceCurrencyService
{
    public function __construct(
        private UserCurrencyService $userCurrency,
        private CurrencyConversionService $conversionService,
    ) {}

    /**
     * Snapshot KES catalog amounts onto the invoice in the customer's currency.
     * Call during invoice creation (amounts on $invoice are KES before this runs).
     */
    public function applySnapshot(Invoice $invoice): void
    {
        if (filled($invoice->currency) && filled($invoice->total_base_kes)) {
            return;
        }

        $user = $invoice->relationLoaded('user')
            ? $invoice->user
            : User::find($invoice->user_id);

        $currency = $this->userCurrency->model($user);
        $this->ensureRates();

        $kesSubtotal = (float) $invoice->subtotal;
        $kesTax = (float) $invoice->tax;
        $kesTotal = (float) $invoice->total;
        $rate = (float) $currency->exchange_rate;

        $invoice->currency = $currency->code;
        $invoice->exchange_rate = $currency->code === config('currency.base', 'KES') ? 1 : $rate;
        $invoice->subtotal_base_kes = $kesSubtotal;
        $invoice->tax_base_kes = $kesTax;
        $invoice->total_base_kes = $kesTotal;

        if ($currency->code !== config('currency.base', 'KES')) {
            $decimals = $this->userCurrency->decimalsFor($currency->code);
            $invoice->subtotal = round($currency->convertFromKES($kesSubtotal), $decimals);
            $invoice->tax = round($currency->convertFromKES($kesTax), $decimals);
            $invoice->total = round($currency->convertFromKES($kesTotal), $decimals);
        }
    }

    public function convertItemsToInvoiceCurrency(Invoice $invoice): void
    {
        if ($invoice->currency === config('currency.base', 'KES')) {
            return;
        }

        $rate = (float) $invoice->exchange_rate;
        $decimals = $this->userCurrency->decimalsFor($invoice->currency);

        $invoice->items()->each(function (InvoiceItem $item) use ($rate, $decimals) {
            $item->update([
                'unit_price' => round((float) $item->unit_price * $rate, $decimals),
                'amount' => round((float) $item->amount * $rate, $decimals),
            ]);
        });
    }

    /**
     * @return array{currency: string, amount: float}
     */
    public function settlementAmount(Invoice $invoice, string $targetCurrency): array
    {
        $this->ensureRates();

        $remainingKes = $this->remainingBaseKes($invoice);

        if ($targetCurrency === $invoice->currency) {
            return [
                'currency' => $invoice->currency,
                'amount' => round($invoice->getAmountRemaining(), $this->userCurrency->decimalsFor($invoice->currency)),
            ];
        }

        if ($targetCurrency === config('currency.base', 'KES')) {
            return [
                'currency' => config('currency.base', 'KES'),
                'amount' => round($remainingKes, 0),
            ];
        }

        $converted = Currency::convert($remainingKes, config('currency.base', 'KES'), $targetCurrency);

        return [
            'currency' => $targetCurrency,
            'amount' => round($converted, $this->userCurrency->decimalsFor($targetCurrency)),
        ];
    }

    public function remainingBaseKes(Invoice $invoice): float
    {
        $invoice->refresh();
        $totalKes = (float) ($invoice->total_base_kes ?? $invoice->total);
        $remaining = $invoice->getAmountRemaining();
        $invoiceTotal = (float) $invoice->total;

        if ($invoiceTotal <= 0) {
            return 0;
        }

        return round($totalKes * ($remaining / $invoiceTotal), 2);
    }

    private function ensureRates(): void
    {
        if ($this->conversionService->areRatesStale()) {
            try {
                $this->conversionService->updateExchangeRates();
            } catch (\Throwable) {
                // Keep database rates.
            }
        }
    }
}
