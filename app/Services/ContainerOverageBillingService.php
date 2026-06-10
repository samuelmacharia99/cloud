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

        $period = $this->resolveBillingPeriod($service);
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

        $nodeCpuCores = max(1, (int) ($deployment->node?->cpu_cores ?? 1));

        $avgCpuPercent = ContainerMetric::averageCpuPercent($deployment, $from, $to);
        $avgMemoryMb = ContainerMetric::averageMemoryMb($deployment, $from, $to);
        $avgDiskGb = ContainerMetric::averageDiskUsedGb($deployment, $from, $to);

        $avgCpuCores = ($avgCpuPercent / 100) * $nodeCpuCores;
        $avgMemoryGb = $avgMemoryMb / 1024;
        $includedMemoryGb = $included['memory_mb'] / 1024;

        $cpuOverageHours = max(0, $avgCpuCores - $included['cpu']) * $billingHours;
        $memoryOverageGbHours = max(0, $avgMemoryGb - $includedMemoryGb) * $billingHours;
        $diskOverageGbHours = max(0, $avgDiskGb - $included['disk_gb']) * $billingHours;

        $cpuRate = (float) $product->cpu_overage_rate;
        $ramRate = (float) $product->ram_overage_rate;
        $diskRate = (float) $product->disk_overage_rate;

        if ($cpuOverageHours > 0 && $cpuRate > 0) {
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
            );
        }

        if ($memoryOverageGbHours > 0 && $ramRate > 0) {
            $this->appendOverageItem(
                $invoice,
                $service,
                $product,
                sprintf(
                    'RAM Overage — %s GB-hours (included: %s GB, avg usage: %s GB) @ KES %s/GB-hour',
                    $this->formatQuantity($memoryOverageGbHours),
                    $this->formatQuantity($includedMemoryGb),
                    $this->formatQuantity($avgMemoryGb),
                    $this->formatRate($ramRate)
                ),
                $memoryOverageGbHours,
                $ramRate,
            );
        }

        if ($diskOverageGbHours > 0 && $diskRate > 0 && $included['disk_gb'] > 0) {
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
            );
        }
    }

    /**
     * Billing period for overage: from previous due date through current due date (or now, whichever is earlier).
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    public function resolveBillingPeriod(Service $service): ?array
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
    ): void {
        $amount = round($quantity * $unitPrice, 2);
        $breakdown = TaxService::calculate($amount);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => $description,
            'quantity' => round($quantity, 4),
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ]);

        $invoice->increment('subtotal', $breakdown['subtotal']);
        $invoice->increment('tax', $breakdown['tax']);
        $invoice->increment('total', $breakdown['total']);
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

    private function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatRate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
