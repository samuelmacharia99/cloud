<?php

namespace App\Services\Billing;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\CurrencyConversionService;
use App\Services\UserCurrencyService;
use InvalidArgumentException;

class InvoiceCurrencyService
{
    public function __construct(
        private UserCurrencyService $userCurrency,
        private CurrencyConversionService $conversionService,
    ) {}

    /**
     * Invoices that are always KES ledger (wallet top-ups, wholesale pushes, reseller subscriptions).
     */
    public function isKesLedgerInvoice(Invoice $invoice): bool
    {
        $number = (string) $invoice->invoice_number;

        if (str_starts_with($number, 'TOPUP-') || str_starts_with($number, 'PUSH-')) {
            return true;
        }

        if ($invoice->type === 'reseller_subscription') {
            return true;
        }

        $user = $invoice->relationLoaded('user')
            ? $invoice->user
            : User::find($invoice->user_id);

        // Reseller B2B invoices (wallet, wholesale domains, packages) are always KES ledger.
        if ($user?->is_reseller) {
            return true;
        }

        $notes = (string) ($invoice->notes ?? '');

        return str_contains($notes, 'Wallet top-up')
            || str_contains($notes, 'wholesale')
            || str_contains($notes, 'Wholesale');
    }

    /**
     * Snapshot KES catalog amounts onto the invoice in the customer's currency.
     * Amounts on $invoice before this runs must be KES catalog/base amounts.
     */
    public function applySnapshot(Invoice $invoice): void
    {
        if (filled($invoice->currency) && filled($invoice->total_base_kes) && ! $this->isKesLedgerInvoice($invoice)) {
            return;
        }

        if ($this->isKesLedgerInvoice($invoice)) {
            $this->applyKesLedgerSnapshot($invoice);

            return;
        }

        $user = $invoice->relationLoaded('user')
            ? $invoice->user
            : User::find($invoice->user_id);

        $currency = $this->userCurrency->model($user);
        $this->ensureBillingRatesReady($currency->code);

        $kesSubtotal = (float) $invoice->subtotal;
        $kesTax = (float) $invoice->tax;
        $kesTotal = (float) $invoice->total;
        $rate = $this->effectiveRate($currency);

        $invoice->currency = $currency->code;
        $invoice->exchange_rate = $rate;
        $invoice->subtotal_base_kes = $kesSubtotal;
        $invoice->tax_base_kes = $kesTax;
        $invoice->total_base_kes = $kesTotal;

        if ($currency->code === config('currency.base', 'KES')) {
            return;
        }

        $decimals = $this->userCurrency->decimalsFor($currency->code);
        $invoice->subtotal = round($kesSubtotal * $rate, $decimals);
        $invoice->tax = round($kesTax * $rate, $decimals);
        $invoice->total = round($kesTotal * $rate, $decimals);
    }

    public function applyKesLedgerSnapshot(Invoice $invoice): void
    {
        $kesSubtotal = (float) $invoice->subtotal;
        $kesTax = (float) $invoice->tax;
        $kesTotal = (float) $invoice->total;

        $invoice->currency = config('currency.base', 'KES');
        $invoice->exchange_rate = 1;
        $invoice->subtotal_base_kes = $kesSubtotal;
        $invoice->tax_base_kes = $kesTax;
        $invoice->total_base_kes = $kesTotal;
        $invoice->subtotal = $kesSubtotal;
        $invoice->tax = $kesTax;
        $invoice->total = $kesTotal;
    }

    /**
     * Re-run snapshot when admin/reseller edits KES catalog totals on an existing invoice.
     */
    public function refreshSnapshotFromKesTotals(Invoice $invoice): void
    {
        if ($this->isKesLedgerInvoice($invoice)) {
            $this->applyKesLedgerSnapshot($invoice);
            $invoice->save();
            $this->syncAllItems($invoice);

            return;
        }

        $kesSubtotal = (float) ($invoice->subtotal_base_kes ?? $invoice->subtotal);
        $kesTax = (float) ($invoice->tax_base_kes ?? $invoice->tax);
        $kesTotal = (float) ($invoice->total_base_kes ?? $invoice->total);

        $invoice->subtotal = $kesSubtotal;
        $invoice->tax = $kesTax;
        $invoice->total = $kesTotal;
        $invoice->currency = null;
        $invoice->exchange_rate = null;
        $invoice->subtotal_base_kes = null;
        $invoice->tax_base_kes = null;
        $invoice->total_base_kes = null;

        $this->applySnapshot($invoice);
        $invoice->save();
        $this->syncAllItems($invoice);
    }

    public function convertItemToInvoiceCurrency(InvoiceItem $item): void
    {
        if (! $item->invoice_id) {
            return;
        }

        $invoice = Invoice::query()->find($item->invoice_id);

        if (! $invoice || $invoice->displayCurrency() === config('currency.base', 'KES')) {
            return;
        }

        $rate = (float) $invoice->exchange_rate;

        if ($rate <= 0) {
            return;
        }

        $decimals = $this->userCurrency->decimalsFor($invoice->displayCurrency());
        $item->unit_price = round((float) $item->unit_price * $rate, $decimals);
        $item->amount = round((float) $item->amount * $rate, $decimals);
    }

    public function syncAllItems(Invoice $invoice): void
    {
        if ($invoice->displayCurrency() === config('currency.base', 'KES')) {
            return;
        }

        $rate = (float) $invoice->exchange_rate;

        if ($rate <= 0) {
            return;
        }

        $decimals = $this->userCurrency->decimalsFor($invoice->displayCurrency());

        $invoice->items()->each(function (InvoiceItem $item) use ($rate, $decimals) {
            $baseUnit = $this->itemBaseKesAmount($item, 'unit_price', $rate, $decimals);
            $baseAmount = $this->itemBaseKesAmount($item, 'amount', $rate, $decimals);

            $item->update([
                'unit_price' => round($baseUnit * $rate, $decimals),
                'amount' => round($baseAmount * $rate, $decimals),
            ]);
        });
    }

    /**
     * @return array{currency: string, amount: float}
     */
    public function settlementAmount(Invoice $invoice, string $targetCurrency): array
    {
        $targetCurrency = strtoupper($targetCurrency);
        $invoice->refresh();

        $remainingKes = $this->remainingBaseKes($invoice);
        $remainingDisplay = $invoice->getAmountRemaining();
        $decimals = $this->userCurrency->decimalsFor($targetCurrency);

        if ($remainingDisplay <= 0 || $remainingKes <= 0) {
            return [
                'currency' => $targetCurrency,
                'amount' => 0,
            ];
        }

        if ($targetCurrency === $invoice->displayCurrency()) {
            return [
                'currency' => $invoice->displayCurrency(),
                'amount' => round($remainingDisplay, $decimals),
            ];
        }

        if ($targetCurrency === config('currency.base', 'KES')) {
            return [
                'currency' => config('currency.base', 'KES'),
                'amount' => round($remainingKes, 0),
            ];
        }

        if ($invoice->displayCurrency() === $targetCurrency) {
            return [
                'currency' => $targetCurrency,
                'amount' => round($remainingDisplay, $decimals),
            ];
        }

        $this->ensureBillingRatesReady($targetCurrency);

        if ($invoice->displayCurrency() !== config('currency.base', 'KES')) {
            $invoiceRate = (float) $invoice->exchange_rate;
            if ($invoiceRate > 0) {
                $viaInvoiceCurrency = Currency::convert(
                    $remainingDisplay,
                    $invoice->displayCurrency(),
                    $targetCurrency
                );

                return [
                    'currency' => $targetCurrency,
                    'amount' => round($viaInvoiceCurrency, $decimals),
                ];
            }
        }

        $converted = Currency::convert($remainingKes, config('currency.base', 'KES'), $targetCurrency);

        return [
            'currency' => $targetCurrency,
            'amount' => round($converted, $decimals),
        ];
    }

    public function paymentAmountInInvoiceCurrency(Invoice $invoice, float $paymentAmount, string $paymentCurrency): float
    {
        $paymentCurrency = strtoupper($paymentCurrency);
        $invoiceCurrency = $invoice->displayCurrency();

        if ($paymentCurrency === $invoiceCurrency) {
            return round($paymentAmount, $this->userCurrency->decimalsFor($invoiceCurrency));
        }

        if ($paymentCurrency === config('currency.base', 'KES') && $invoiceCurrency !== config('currency.base', 'KES')) {
            $rate = (float) $invoice->exchange_rate;

            if ($rate > 0) {
                return round($paymentAmount * $rate, $this->userCurrency->decimalsFor($invoiceCurrency));
            }
        }

        return round(
            Currency::convert($paymentAmount, $paymentCurrency, $invoiceCurrency),
            $this->userCurrency->decimalsFor($invoiceCurrency)
        );
    }

    public function paymentOverpaymentInKes(Invoice $invoice, float $paymentAmount, string $paymentCurrency): float
    {
        $invoiceCurrency = $invoice->displayCurrency();
        $paymentInInvoice = $this->paymentAmountInInvoiceCurrency($invoice, $paymentAmount, $paymentCurrency);
        $overInInvoice = max(0, $paymentInInvoice - $invoice->getAmountRemaining());

        if ($overInInvoice <= 0) {
            return 0;
        }

        if ($invoiceCurrency === config('currency.base', 'KES')) {
            return round($overInInvoice, 2);
        }

        $rate = (float) $invoice->exchange_rate;

        if ($rate > 0) {
            return round($overInInvoice / $rate, 2);
        }

        return round(Currency::convert($overInInvoice, $invoiceCurrency, config('currency.base', 'KES')), 2);
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

    public function ensureBillingRatesReady(?string $currencyCode = null): void
    {
        $this->ensureRates();

        $codes = $currencyCode
            ? [strtoupper($currencyCode)]
            : Currency::active()->pluck('code')->all();

        foreach ($codes as $code) {
            if ($code === config('currency.base', 'KES')) {
                continue;
            }

            $currency = Currency::where('code', $code)->first();

            if (! $currency) {
                throw new InvalidArgumentException("Currency {$code} is not available for billing.");
            }

            if (! $this->isRateUsable($currency)) {
                throw new InvalidArgumentException(
                    "Exchange rate for {$code} is not ready. Ask an administrator to refresh currency rates."
                );
            }
        }
    }

    public function isRateUsable(Currency $currency): bool
    {
        if ($currency->code === config('currency.base', 'KES')) {
            return true;
        }

        $rate = (float) $currency->exchange_rate;

        if ($rate <= 0 || $rate === 1.0) {
            return false;
        }

        if (! $currency->rate_updated_at) {
            return false;
        }

        $maxAgeHours = (int) config('currency.max_rate_age_hours', 48);

        return $currency->rate_updated_at->diffInHours(now()) <= $maxAgeHours;
    }

    private function effectiveRate(Currency $currency): float
    {
        if ($currency->code === config('currency.base', 'KES')) {
            return 1.0;
        }

        if (! $this->isRateUsable($currency)) {
            throw new InvalidArgumentException(
                "Exchange rate for {$currency->code} is not ready. Please try again shortly or contact support."
            );
        }

        return (float) $currency->exchange_rate;
    }

    private function ensureRates(): void
    {
        if ($this->conversionService->areRatesStale()) {
            try {
                $this->conversionService->updateExchangeRates();
            } catch (\Throwable) {
                // Fall back to database rates; validation will catch unusable rates.
            }
        }
    }

    private function itemBaseKesAmount(InvoiceItem $item, string $field, float $rate, int $decimals): float
    {
        $value = (float) $item->{$field};

        return round($value / max($rate, 0.00000001), $decimals);
    }
}
