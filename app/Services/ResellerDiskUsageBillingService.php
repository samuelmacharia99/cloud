<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\Carbon;

class ResellerDiskUsageBillingService
{
    public function __construct(
        private ResellerDiskUsageService $diskUsage,
    ) {}

    public function addUsageItemsToSubscriptionInvoice(Invoice $invoice, User $reseller, bool $renewal): void
    {
        if ($invoice->type !== 'reseller_subscription' || ! $renewal) {
            return;
        }

        if (InvoiceItem::query()->where('invoice_id', $invoice->id)->where('product_type', 'reseller_disk_usage')->exists()) {
            return;
        }

        $period = $this->resolveBillingPeriod($reseller);
        if ($period === null) {
            return;
        }

        ['from' => $from, 'to' => $to] = $period;
        $usage = $this->diskUsage->averageUsageForPeriod($reseller, $from, $to);
        $poolGb = $this->diskUsage->diskPoolGb($reseller);
        $overageGb = max(0, $usage['total_used_gb'] - $poolGb);
        $rate = $this->diskUsage->diskOverageRate($reseller);

        $description = sprintf(
            'Disk usage (%s to %s) — DirectAdmin %.2f GB, Containers %.2f GB (included pool: %d GB, avg total: %.2f GB)',
            $from->format('M j, Y'),
            $to->format('M j, Y'),
            $usage['directadmin_used_gb'],
            $usage['container_used_gb'],
            $poolGb,
            $usage['total_used_gb']
        );

        $this->appendItem($invoice, $description, 1, 0, 'reseller_disk_usage');

        if ($overageGb > 0 && $rate > 0) {
            $amount = round($overageGb * $rate, 2);
            $overageDescription = sprintf(
                'Disk overage — %.2f GB above %d GB pool @ KES %s/GB',
                $overageGb,
                $poolGb,
                rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.')
            );

            $this->appendItem($invoice, $overageDescription, round($overageGb, 4), $rate, 'reseller_disk_overage');
        }
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    private function resolveBillingPeriod(User $reseller): ?array
    {
        if (! $reseller->package_expires_at) {
            return null;
        }

        $periodEnd = Carbon::parse($reseller->package_expires_at)->startOfDay();
        $cycle = $reseller->resellerPackage?->billing_cycle ?? 'monthly';

        $periodStart = match ($cycle) {
            'annually' => $periodEnd->copy()->subYear(),
            default => $periodEnd->copy()->subMonth(),
        };

        if ($periodStart->greaterThanOrEqualTo($periodEnd)) {
            return null;
        }

        return ['from' => $periodStart, 'to' => $periodEnd];
    }

    private function appendItem(
        Invoice $invoice,
        string $description,
        float $quantity,
        float $unitPrice,
        string $productType,
    ): void {
        $amount = round($quantity * $unitPrice, 2);
        $breakdown = TaxService::calculate($amount);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => null,
            'product_type' => $productType,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ]);

        $invoice->increment('subtotal', $breakdown['subtotal']);
        $invoice->increment('tax', $breakdown['tax']);
        $invoice->increment('total', $breakdown['total']);
    }
}
