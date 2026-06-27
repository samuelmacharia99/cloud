<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    /**
     * Next invoice number in {prefix}-{YYYY}-{sequence} format (renewal/cron style).
     */
    public function nextYearly(?string $prefix = null, ?int $year = null): string
    {
        return DB::transaction(function () use ($prefix, $year) {
            return $this->buildYearlyNumber($prefix, $year);
        });
    }

    /**
     * Next invoice number in {prefix}-{YYYYMMDD}-{sequence} format (checkout style).
     */
    public function nextDaily(?string $prefix = null, ?\DateTimeInterface $date = null): string
    {
        return DB::transaction(function () use ($prefix, $date) {
            return $this->buildDailyNumber($prefix, $date);
        });
    }

    /**
     * Create a record using a freshly allocated invoice number, retrying on duplicate-key races.
     *
     * @template T
     *
     * @param  callable(string): T  $create
     * @return T
     */
    public function createWithUniqueNumber(callable $create, bool $yearly = true): mixed
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($create, $yearly) {
                    $number = $yearly
                        ? $this->buildYearlyNumber()
                        : $this->buildDailyNumber();

                    return $create($number);
                });
            } catch (QueryException $e) {
                if (! $this->isDuplicateInvoiceNumber($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to create invoice with a unique number.');
    }

    private function buildYearlyNumber(?string $prefix = null, ?int $year = null): string
    {
        $prefix = $prefix ?? Setting::getValue('invoice_prefix', 'INV');
        $year = $year ?? (int) now()->format('Y');
        $sequence = $this->maxSequence("{$prefix}-{$year}-") + 1;

        return "{$prefix}-{$year}-".str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function buildDailyNumber(?string $prefix = null, ?\DateTimeInterface $date = null): string
    {
        $prefix = $prefix ?? Setting::getValue('invoice_prefix', 'INV');
        $datePart = ($date ?? now())->format('Ymd');
        $sequence = $this->maxSequence("{$prefix}-{$datePart}-") + 1;

        return "{$prefix}-{$datePart}-".str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function maxSequence(string $prefix): int
    {
        return Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('invoice_number')
            ->map(function (string $number) use ($prefix) {
                if (! str_starts_with($number, $prefix)) {
                    return 0;
                }

                $suffix = substr($number, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }

    private function isDuplicateInvoiceNumber(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'invoices_invoice_number_unique')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
