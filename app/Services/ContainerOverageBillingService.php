<?php

namespace App\Services;

use App\Models\ContainerMetric;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MailUsageSnapshot;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\UsageBillingProfileService;
use Illuminate\Support\Carbon;

class ContainerOverageBillingService
{
    public function __construct(
        private UsageBillingProfileService $usageProfile,
    ) {}

    /**
     * Add CPU, RAM, disk (and mailbox) overage line items when usage exceeds included limits.
     *
     * Runs when:
     * - service billing_mode is usage, or
     * - product.overage_enabled (grandfathered package overage)
     */
    public function addOverageItemsToInvoice(
        Invoice $invoice,
        Service $service,
    ): void {
        $service->loadMissing(['product.containerTemplate', 'containerDeployment.node']);

        $product = $service->product;
        $deployment = $service->containerDeployment;
        $isUsage = $this->usageProfile->serviceUsesUsageBilling($service);

        if (! $isUsage && ! $product?->overage_enabled) {
            return;
        }

        if (! $deployment && ! $isUsage) {
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

        $included = $this->usageProfile->effectiveContainerIncluded($service);
        $rates = $this->usageProfile->effectiveRates($service);
        $graceCpu = $this->usageProfile->applyGrace($included['cpu']);
        $graceMemMb = $this->usageProfile->applyGrace($included['memory_mb']);
        $graceDisk = $this->usageProfile->applyGrace($included['disk_gb']);

        if ($deployment) {
            $cpuLimitCores = max(0.1, (float) ($deployment->cpu_limit ?? $included['cpu'] ?? 1));

            $avgCpuPercent = ContainerMetric::averageCpuPercent($deployment, $from, $to);
            $peakMemoryMb = ContainerMetric::peakMemoryMb($deployment, $from, $to);
            $avgDiskGb = ContainerMetric::averageDiskUsedGb($deployment, $from, $to);

            $avgCpuCores = ($avgCpuPercent / 100) * $cpuLimitCores;
            $peakMemoryGb = $peakMemoryMb / 1024;
            $includedMemoryGb = $graceMemMb / 1024;

            $cpuOverageHours = max(0, $avgCpuCores - $graceCpu) * $billingHours;
            $memoryOverageGbHours = max(0, $peakMemoryGb - $includedMemoryGb) * $billingHours;
            $diskOverageGbHours = max(0, $avgDiskGb - $graceDisk) * $billingHours;

            $cpuRate = $rates['cpu_per_core_hour'];
            $ramRate = $rates['ram_per_gb_hour'];
            $diskRate = $rates['disk_per_gb_hour'];

            if ($cpuOverageHours > 0 && $cpuRate > 0) {
                $this->appendOverageItem(
                    $invoice,
                    $service,
                    $product,
                    sprintf(
                        'CPU Overage — %s core-hours (included: %s cores + %s%% grace, avg usage: %s cores) @ KES %s/core-hour',
                        $this->formatQuantity($cpuOverageHours),
                        $this->formatQuantity($included['cpu']),
                        $this->formatQuantity($this->usageProfile->gracePercent()),
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
                        'RAM Overage — %s GB-hours (included: %s GB + grace, peak usage: %s GB) @ KES %s/GB-hour',
                        $this->formatQuantity($memoryOverageGbHours),
                        $this->formatQuantity($included['memory_mb'] / 1024),
                        $this->formatQuantity($peakMemoryGb),
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
                        'Disk Overage — %s GB-hours (included: %s GB + grace, avg usage: %s GB) @ KES %s/GB-hour',
                        $this->formatQuantity($diskOverageGbHours),
                        $this->formatQuantity($included['disk_gb']),
                        $this->formatQuantity($avgDiskGb),
                        $this->formatRate($diskRate)
                    ),
                    $diskOverageGbHours,
                    $diskRate,
                );
            }

            if (config('usage_billing.bandwidth_billing_enabled') && $rates['bandwidth_per_gb'] > 0) {
                $this->appendBandwidthOverage($invoice, $service, $product, $deployment->id, $from, $to, $rates['bandwidth_per_gb']);
            }
        }

        if ($isUsage) {
            $this->appendMailboxOverage($invoice, $service, $product, $from, $to, $rates['mailbox_per_month']);
        }
    }

    /**
     * Whether renewal invoice generation should attempt overage lines.
     */
    public function shouldBillOverage(Service $service): bool
    {
        $service->loadMissing(['product', 'containerDeployment']);

        if ($this->usageProfile->serviceUsesUsageBilling($service)) {
            return true;
        }

        return (bool) $service->product?->overage_enabled && $service->containerDeployment;
    }

    /**
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

    private function appendMailboxOverage(
        Invoice $invoice,
        Service $service,
        ?Product $product,
        Carbon $from,
        Carbon $to,
        float $rate,
    ): void {
        if ($rate <= 0) {
            return;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $emailServiceId = $meta['bundled_email_service_id'] ?? null;
        $emailService = $emailServiceId ? Service::query()->find($emailServiceId) : null;
        if (! $emailService) {
            return;
        }

        $includedMailboxes = (int) (($service->included_limits['mailboxes'] ?? null)
            ?: $this->usageProfile->includedLimits()['mailboxes']);
        $graceMailboxes = (int) ceil($this->usageProfile->applyGrace($includedMailboxes));
        $peak = MailUsageSnapshot::peakMailboxCount($emailService, $from, $to);
        $overage = max(0, $peak - $graceMailboxes);

        if ($overage <= 0) {
            return;
        }

        $this->appendOverageItem(
            $invoice,
            $service,
            $product,
            sprintf(
                'Mailbox Overage — %s mailbox(es) over included %s (+ grace) @ KES %s/mailbox',
                $overage,
                $includedMailboxes,
                $this->formatRate($rate)
            ),
            (float) $overage,
            $rate,
        );
    }

    private function appendBandwidthOverage(
        Invoice $invoice,
        Service $service,
        ?Product $product,
        int $deploymentId,
        Carbon $from,
        Carbon $to,
        float $rate,
    ): void {
        $minRx = ContainerMetric::query()->where('container_deployment_id', $deploymentId)->whereBetween('recorded_at', [$from, $to])->min('net_io_rx_bytes');
        $maxRx = ContainerMetric::query()->where('container_deployment_id', $deploymentId)->whereBetween('recorded_at', [$from, $to])->max('net_io_rx_bytes');
        $minTx = ContainerMetric::query()->where('container_deployment_id', $deploymentId)->whereBetween('recorded_at', [$from, $to])->min('net_io_tx_bytes');
        $maxTx = ContainerMetric::query()->where('container_deployment_id', $deploymentId)->whereBetween('recorded_at', [$from, $to])->max('net_io_tx_bytes');

        $rxGb = ($minRx !== null && $maxRx !== null) ? max(0, ((float) $maxRx - (float) $minRx) / (1024 ** 3)) : 0;
        $txGb = ($minTx !== null && $maxTx !== null) ? max(0, ((float) $maxTx - (float) $minTx) / (1024 ** 3)) : 0;
        $totalGb = $rxGb + $txGb;

        if ($totalGb <= 0) {
            return;
        }

        $this->appendOverageItem(
            $invoice,
            $service,
            $product,
            sprintf(
                'Bandwidth Overage — %s GB transferred @ KES %s/GB',
                $this->formatQuantity($totalGb),
                $this->formatRate($rate)
            ),
            $totalGb,
            $rate,
        );
    }

    private function appendOverageItem(
        Invoice $invoice,
        Service $service,
        ?Product $product,
        string $description,
        float $quantity,
        float $unitPrice,
    ): void {
        $amount = round($quantity * $unitPrice, 2);
        $invoice->loadMissing('user');
        $breakdown = TaxService::calculateForUser($amount, $invoice->user);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product?->id,
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
