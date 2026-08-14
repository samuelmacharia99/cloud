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
    public function createWithUniqueNumber(
        callable $create,
        bool $yearly = true,
        ?string $prefix = null,
    ): mixed {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($create, $yearly, $prefix) {
                    $number = $yearly
                        ? $this->buildYearlyNumber($prefix)
                        : $this->buildDailyNumber($prefix);

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
        $numberPrefix = "{$prefix}-{$year}-";
        $sequence = $this->allocateSequence($numberPrefix);

        return $numberPrefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function buildDailyNumber(?string $prefix = null, ?\DateTimeInterface $date = null): string
    {
        $prefix = $prefix ?? Setting::getValue('invoice_prefix', 'INV');
        $datePart = ($date ?? now())->format('Ymd');
        $numberPrefix = "{$prefix}-{$datePart}-";
        $sequence = $this->allocateSequence($numberPrefix);

        return $numberPrefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function allocateSequence(string $series): int
    {
        $initial = $this->maxExistingSequence($series) + 1;

        DB::table('invoice_number_sequences')->insertOrIgnore([
            'series' => $series,
            'next_value' => $initial,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('invoice_number_sequences')
            ->where('series', $series)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            throw new \RuntimeException('Unable to lock the invoice number sequence.');
        }

        $sequence = max((int) $row->next_value, $this->maxExistingSequence($series) + 1);
        DB::table('invoice_number_sequences')
            ->where('series', $series)
            ->update([
                'next_value' => $sequence + 1,
                'updated_at' => now(),
            ]);

        return $sequence;
    }

    private function maxExistingSequence(string $prefix): int
    {
        return Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->pluck('invoice_number')
            ->map(fn (string $number) => ctype_digit($suffix = substr($number, strlen($prefix)))
                ? (int) $suffix
                : 0)
            ->max() ?? 0;
    }

    private function isDuplicateInvoiceNumber(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'invoices_invoice_number_unique')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
