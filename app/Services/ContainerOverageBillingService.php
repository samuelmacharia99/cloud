<?php

namespace App\Services;

use App\Models\ContainerMetric;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Carbon;

class ContainerOverageBillingService
{
    /**
     * Add CPU, RAM, and disk overage line items when usage exceeds the product's included limits.
     */
    public function addOverageItemsToInvoice(
        Invoice $invoice,
        Service $service,
    ): void {
        $service->loadMissing(['product.containerTemplate', 'containerDeployment.node']);

        $product = $service->product;
        $deployment = $service->containerDeployment;

        if (! $product?->overage_enabled || ! $deployment) {
            return;
        }

        $period = $this->resolveBillingPeriod($service, $invoice);
        if ($period === null) {
            return;
        }

        ['from' => $from, 'to' => $to] = $period;
        $billingHours = (float) $from->diffInHours($to);

        if ($billingHours <= 0) {
            return;
        }

        $included = $product->getIncludedContainerLimits(
            $product->containerTemplate,
            $deployment
        );

        $avgCpuPercent = ContainerMetric::averageCpuPercent($deployment, $from, $to);
        $avgDiskGb = ContainerMetric::averageDiskUsedGb($deployment, $from, $to);

        // Docker reports 100% per fully-used core. It is not a percentage of the
        // configured plan limit, so multiplying by that limit over-bills multi-core plans.
        $avgCpuCores = $avgCpuPercent / 100;
        $includedMemoryGb = $included['memory_mb'] / 1024;
        $memoryOverage = ContainerMetric::memoryOverageGbHours(
            $deployment,
            $from,
            $to,
            $includedMemoryGb,
        );

        $cpuOverageHours = max(0, $avgCpuCores - $included['cpu']) * $billingHours;
        $memoryOverageGbHours = $memoryOverage['gb_hours'];
        $diskOverageGbHours = max(0, $avgDiskGb - $included['disk_gb']) * $billingHours;

        $cpuRate = (float) $product->cpu_overage_rate;
        $ramRate = (float) $product->ram_overage_rate;
        $diskRate = (float) $product->disk_overage_rate;
        $activeTypes = [];

        if ($cpuOverageHours > 0 && $cpuRate > 0) {
            $activeTypes[] = 'container_cpu_overage';
            $this->appendOverageItem(
                $invoice,
                $service,
                $product,
                sprintf(
                    'CPU Overage — %s core-hours (included: %s cores, avg usage: %s cores) @ KES %s/core-hour',
                    $this->formatQuantity($cpuOverageHours),
                    $this->formatQuantity($included['cpu']),
                    $this->formatQuantity($avgCpuCores),
                    $this->formatRate($cpuRate)
                ),
                $cpuOverageHours,
                $cpuRate,
                'container_cpu_overage',
                $from,
                $to,
            );
        }

        if ($memoryOverageGbHours > 0 && $ramRate > 0) {
            $activeTypes[] = 'container_ram_overage';
            $this->appendOverageItem(
                $invoice,
                $service,
                $product,
                sprintf(
                    'RAM Overage — %s GB-hours (included: %s GB, avg usage: %s GB, peak: %s GB) @ KES %s/GB-hour',
                    $this->formatQuantity($memoryOverageGbHours),
                    $this->formatQuantity($includedMemoryGb),
                    $this->formatQuantity($memoryOverage['avg_usage_gb']),
                    $this->formatQuantity($memoryOverage['peak_usage_gb']),
                    $this->formatRate($ramRate)
                ),
                $memoryOverageGbHours,
                $ramRate,
                'container_ram_overage',
                $from,
                $to,
            );
        }

        if ($diskOverageGbHours > 0 && $diskRate > 0 && $included['disk_gb'] > 0) {
            $activeTypes[] = 'container_disk_overage';
            $this->appendOverageItem(
                $invoice,
                $service,
                $product,
                sprintf(
                    'Disk Overage — %s GB-hours (included: %s GB, avg usage: %s GB) @ KES %s/GB-hour',
                    $this->formatQuantity($diskOverageGbHours),
                    $this->formatQuantity($included['disk_gb']),
                    $this->formatQuantity($avgDiskGb),
                    $this->formatRate($diskRate)
                ),
                $diskOverageGbHours,
                $diskRate,
                'container_disk_overage',
                $from,
                $to,
            );
        }

        $this->removeStaleOverageItems($invoice, $service, $activeTypes);
    }

    /**
     * Billing period for overage: from previous due date through current due date (or now, whichever is earlier).
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    public function resolveBillingPeriod(Service $service, ?Invoice $invoice = null): ?array
    {
        if (! $service->next_due_date) {
            return null;
        }

        $periodEnd = Carbon::parse($service->next_due_date);
        $periodStart = $this->subtractBillingCycle($periodEnd, $service->billing_cycle ?? 'monthly');

        $earliest = Carbon::parse($service->commenced_at ?? $service->created_at);
        if ($periodStart->lessThan($earliest)) {
            $periodStart = $earliest->copy();
        }

        // Renewal invoices are generated before their due date. Preserve a continuous
        // settlement cursor so usage after the previous invoice snapshot is carried onto
        // the next invoice instead of disappearing between billing cycles.
        $cursor = $this->settlementCursor($service, $invoice);
        if ($cursor !== null && $cursor->greaterThan($earliest) && $cursor->lessThan($periodEnd)) {
            $periodStart = $cursor;
        }

        $metricsEnd = Carbon::now()->min($periodEnd);
        if ($periodStart->greaterThanOrEqualTo($metricsEnd)) {
            return null;
        }

        return [
            'from' => $periodStart,
            'to' => $metricsEnd,
        ];
    }

    private function appendOverageItem(
        Invoice $invoice,
        Service $service,
        Product $product,
        string $description,
        float $quantity,
        float $unitPrice,
        string $productType,
        Carbon $billingFrom,
        Carbon $billingTo,
    ): void {
        $amount = round($quantity * $unitPrice, 2);
        $invoice->loadMissing('user');
        $existing = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->where('service_id', $service->id)
            ->where('product_type', $productType)
            ->first();
        $previousAmount = (float) ($existing?->amount ?? 0);
        $delta = $amount - $previousAmount;

        InvoiceItem::updateOrCreate([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_type' => $productType,
        ], [
            'product_id' => $product->id,
            'description' => $description,
            'quantity' => round($quantity, 4),
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'custom_options' => [
                'metered_billing_from' => $billingFrom->toIso8601String(),
                'metered_billing_to' => $billingTo->toIso8601String(),
            ],
        ]);

        if (abs($delta) < 0.005) {
            return;
        }

        $breakdown = TaxService::calculateForUser($delta, $invoice->user);
        $invoice->increment('subtotal', $breakdown['subtotal']);
        $invoice->increment('tax', $breakdown['tax']);
        $invoice->increment('total', $breakdown['total']);
    }

    /**
     * Re-running invoice generation must update metered charges, not duplicate or retain
     * an overage that later complete-period samples no longer support.
     *
     * @param  list<string>  $activeTypes
     */
    private function removeStaleOverageItems(Invoice $invoice, Service $service, array $activeTypes): void
    {
        $stale = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->where('service_id', $service->id)
            ->whereIn('product_type', [
                'container_cpu_overage',
                'container_ram_overage',
                'container_disk_overage',
            ])
            ->when($activeTypes !== [], fn ($query) => $query->whereNotIn('product_type', $activeTypes))
            ->get();

        foreach ($stale as $item) {
            $invoice->loadMissing('user');
            $breakdown = TaxService::calculateForUser(-((float) $item->amount), $invoice->user);
            $item->delete();
            $invoice->increment('subtotal', $breakdown['subtotal']);
            $invoice->increment('tax', $breakdown['tax']);
            $invoice->increment('total', $breakdown['total']);
        }
    }

    private function subtractBillingCycle(Carbon $dueDate, string $billingCycle): Carbon
    {
        return match ($billingCycle) {
            'monthly' => $dueDate->copy()->subMonth(),
            'quarterly' => $dueDate->copy()->subMonths(3),
            'semi-annual' => $dueDate->copy()->subMonths(6),
            'annual' => $dueDate->copy()->subYear(),
            default => $dueDate->copy()->subMonth(),
        };
    }

    private function settlementCursor(Service $service, ?Invoice $invoice): ?Carbon
    {
        if ($invoice) {
            $current = InvoiceItem::query()
                ->where('invoice_id', $invoice->id)
                ->where('service_id', $service->id)
                ->whereIn('product_type', [
                    'container_cpu_overage',
                    'container_ram_overage',
                    'container_disk_overage',
                ])
                ->first();
            $currentOptions = is_array($current?->custom_options) ? $current->custom_options : [];
            $from = $currentOptions['metered_billing_from'] ?? null;
            if ($from) {
                return Carbon::parse($from);
            }
        }

        $previous = InvoiceItem::query()
            ->where('service_id', $service->id)
            ->when($invoice, fn ($query) => $query->where('invoice_id', '!=', $invoice->id))
            ->where(function ($query) {
                $query->whereIn('product_type', [
                    'container_cpu_overage',
                    'container_ram_overage',
                    'container_disk_overage',
                ])->orWhere('description', 'like', '% Overage — %');
            })
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $previous) {
            return null;
        }

        $previousOptions = is_array($previous->custom_options) ? $previous->custom_options : [];
        $to = $previousOptions['metered_billing_to'] ?? null;

        return Carbon::parse($to ?: $previous->created_at);
    }

    private function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatRate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
