<?php

namespace App\Services\Billing;

use App\Models\ContainerMetric;
use App\Models\MailUsageSnapshot;
use App\Models\Service;
use App\Services\ContainerOverageBillingService;
use Illuminate\Support\Carbon;

/**
 * Builds a customer-facing usage report for the current (or resolved) billing period.
 */
class UsagePeriodReportService
{
    public function __construct(
        private UsageBillingProfileService $profile,
        private ContainerOverageBillingService $overageBilling,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Service $service): array
    {
        $service->loadMissing(['product.containerTemplate', 'containerDeployment', 'user']);

        $period = $this->overageBilling->resolveBillingPeriod($service);
        $included = $this->profile->effectiveContainerIncluded($service);
        $rates = $this->profile->effectiveRates($service);
        $graceIncluded = [
            'cpu' => $this->profile->applyGrace($included['cpu']),
            'memory_mb' => $this->profile->applyGrace($included['memory_mb']),
            'disk_gb' => $this->profile->applyGrace($included['disk_gb']),
        ];

        $usage = [
            'avg_cpu_cores' => 0.0,
            'peak_memory_mb' => 0.0,
            'avg_disk_gb' => 0.0,
            'net_rx_gb' => 0.0,
            'net_tx_gb' => 0.0,
            'mailbox_peak' => 0,
        ];

        $projectedOverage = 0.0;
        $warnings = [];

        if ($period && $service->containerDeployment) {
            $from = $period['from'];
            $to = $period['to'];
            $hours = max(0.0, (float) $from->diffInHours($to));
            $deployment = $service->containerDeployment;
            $cpuLimitCores = max(0.1, (float) ($deployment->cpu_limit ?? $included['cpu'] ?? 1));

            $avgCpuPercent = ContainerMetric::averageCpuPercent($deployment, $from, $to);
            $peakMemoryMb = (float) ContainerMetric::peakMemoryMb($deployment, $from, $to);
            $avgDiskGb = (float) ContainerMetric::averageDiskUsedGb($deployment, $from, $to);

            $avgCpuCores = ($avgCpuPercent / 100) * $cpuLimitCores;
            $usage['avg_cpu_cores'] = $avgCpuCores;
            $usage['peak_memory_mb'] = $peakMemoryMb;
            $usage['avg_disk_gb'] = $avgDiskGb;

            if (config('usage_billing.bandwidth_billing_enabled')) {
                $usage['net_rx_gb'] = $this->bandwidthGb($deployment->id, $from, $to, 'net_io_rx_bytes');
                $usage['net_tx_gb'] = $this->bandwidthGb($deployment->id, $from, $to, 'net_io_tx_bytes');
            }

            $cpuBillable = max(0, $avgCpuCores - $graceIncluded['cpu']) * $hours;
            $ramBillable = max(0, ($peakMemoryMb / 1024) - ($graceIncluded['memory_mb'] / 1024)) * $hours;
            $diskBillable = max(0, $avgDiskGb - $graceIncluded['disk_gb']) * $hours;

            $projectedOverage += $cpuBillable * $rates['cpu_per_core_hour'];
            $projectedOverage += $ramBillable * $rates['ram_per_gb_hour'];
            $projectedOverage += $diskBillable * $rates['disk_per_gb_hour'];

            $warnPct = $this->profile->warnPercent() / 100;
            if ($included['cpu'] > 0 && $avgCpuCores >= $included['cpu'] * $warnPct) {
                $warnings[] = 'CPU usage is approaching your included allotment.';
            }
            if ($included['memory_mb'] > 0 && $peakMemoryMb >= $included['memory_mb'] * $warnPct) {
                $warnings[] = 'Peak RAM is approaching your included allotment.';
            }
            if ($included['disk_gb'] > 0 && $avgDiskGb >= $included['disk_gb'] * $warnPct) {
                $warnings[] = 'Disk usage is approaching your included allotment.';
            }
        }

        $emailService = $this->bundledEmailService($service);
        $mailIncluded = (int) (($service->included_limits['mailboxes'] ?? null) ?: $this->profile->includedLimits()['mailboxes']);
        if ($emailService && $period) {
            $usage['mailbox_peak'] = MailUsageSnapshot::peakMailboxCount($emailService, $period['from'], $period['to']);
            $mailboxOverage = max(0, $usage['mailbox_peak'] - (int) $this->profile->applyGrace($mailIncluded));
            $projectedOverage += $mailboxOverage * $rates['mailbox_per_month'];
            if ($mailIncluded > 0 && $usage['mailbox_peak'] >= $mailIncluded * ($this->profile->warnPercent() / 100)) {
                $warnings[] = 'Mailbox count is approaching your included allotment.';
            }
        }

        $floor = (float) ($service->custom_price ?? $this->profile->floorPriceMonthly());

        return [
            'billing_mode' => $service->billing_mode?->value ?? (string) $service->billing_mode,
            'is_usage' => $this->profile->serviceUsesUsageBilling($service),
            'period' => $period ? [
                'from' => $period['from']->toIso8601String(),
                'to' => $period['to']->toIso8601String(),
                'from_human' => $period['from']->toFormattedDateString(),
                'to_human' => $period['to']->toFormattedDateString(),
            ] : null,
            'floor_price' => $floor,
            'included' => $included,
            'mailboxes_included' => $mailIncluded,
            'grace_percent' => $this->profile->gracePercent(),
            'hard_caps' => $this->profile->hardCaps(),
            'rates' => $rates,
            'usage' => $usage,
            'projected_overage' => round($projectedOverage, 2),
            'projected_total' => round($floor + $projectedOverage, 2),
            'warnings' => $warnings,
            'invoice_items' => $this->recentUsageInvoiceLines($service),
        ];
    }

    private function bundledEmailService(Service $service): ?Service
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $id = $meta['bundled_email_service_id'] ?? null;
        if (! $id) {
            return null;
        }

        return Service::query()->find($id);
    }

    private function bandwidthGb(int $deploymentId, Carbon $from, Carbon $to, string $column): float
    {
        $min = ContainerMetric::query()
            ->where('container_deployment_id', $deploymentId)
            ->whereBetween('recorded_at', [$from, $to])
            ->min($column);
        $max = ContainerMetric::query()
            ->where('container_deployment_id', $deploymentId)
            ->whereBetween('recorded_at', [$from, $to])
            ->max($column);

        if ($min === null || $max === null) {
            return 0.0;
        }

        return max(0, ((float) $max - (float) $min) / (1024 ** 3));
    }

    /**
     * @return list<array{invoice_id: int, invoice_number: string|null, description: string, amount: float}>
     */
    private function recentUsageInvoiceLines(Service $service): array
    {
        return $service->invoiceItems()
            ->with('invoice:id,invoice_number')
            ->where(function ($q) {
                $q->where('description', 'like', '%Overage%')
                    ->orWhere('description', 'like', '%usage%')
                    ->orWhere('description', 'like', '%Mailbox%');
            })
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'invoice_id' => $item->invoice_id,
                'invoice_number' => $item->invoice?->invoice_number,
                'description' => $item->description,
                'amount' => (float) $item->amount,
            ])
            ->all();
    }
}
