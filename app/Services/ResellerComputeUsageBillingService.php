<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDiskUsageSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Bills a reseller for what their application hosting plans committed above
 * the package's CPU, RAM and bandwidth pools over the period just ended.
 * Appended to the package renewal invoice beside the disk lines; a package
 * with no pool or no rate for a resource never gets a line for it.
 */
class ResellerComputeUsageBillingService
{
    public const TYPE_CPU_OVERAGE = 'reseller_cpu_overage';

    public const TYPE_MEMORY_OVERAGE = 'reseller_memory_overage';

    public const TYPE_BANDWIDTH_USAGE = 'reseller_bandwidth_usage';

    public const TYPE_BANDWIDTH_OVERAGE = 'reseller_bandwidth_overage';

    public function __construct(
        private readonly ResellerComputeUsageService $compute,
        private readonly ResellerBandwidthUsageService $bandwidth,
    ) {}

    public function addUsageItemsToSubscriptionInvoice(Invoice $invoice, User $reseller, bool $renewal): void
    {
        if ($invoice->type !== 'reseller_subscription' || ! $renewal) {
            return;
        }
        if (InvoiceItem::query()->where('invoice_id', $invoice->id)->whereIn('product_type', [self::TYPE_CPU_OVERAGE, self::TYPE_MEMORY_OVERAGE, self::TYPE_BANDWIDTH_USAGE])->exists()) {
            return;
        }
        $period = $this->resolveBillingPeriod($reseller);
        if ($period === null) {
            return;
        }
        ['from' => $from, 'to' => $to, 'months' => $months] = $period;
        $package = $reseller->resellerPackage;
        if (! $package) {
            return;
        }

        $allocation = $this->averageAllocationForPeriod($reseller, $from, $to);
        $span = $from->format('M j, Y').' to '.$to->format('M j, Y');

        $cpuPool = (float) $package->cpu_pool_cores;
        $cpuRate = $package->cpu_overage_rate !== null ? (float) $package->cpu_overage_rate : (float) setting('reseller_cpu_overage_rate', 0);
        $cpuOver = $cpuPool > 0 ? round(max(0, $allocation['cpu_cores'] - $cpuPool), 2) : 0.0;
        if ($cpuOver > 0 && $cpuRate > 0) {
            $this->appendItem(
                $invoice,
                sprintf('vCPU overage (%s) — %.2f vCPU above %s vCPU pool @ KES %s/vCPU/month × %d month(s)', $span, $cpuOver, $this->trim($cpuPool), $this->trim($cpuRate), $months),
                round($cpuOver * $months, 4),
                $cpuRate,
                self::TYPE_CPU_OVERAGE,
            );
        }

        $memoryPoolGb = ((int) $package->memory_pool_mb) / 1024;
        $memoryRate = $package->memory_overage_rate !== null ? (float) $package->memory_overage_rate : (float) setting('reseller_memory_overage_rate', 0);
        $memoryOverGb = $memoryPoolGb > 0 ? round(max(0, ($allocation['memory_mb'] / 1024) - $memoryPoolGb), 2) : 0.0;
        if ($memoryOverGb > 0 && $memoryRate > 0) {
            $this->appendItem(
                $invoice,
                sprintf('RAM overage (%s) — %.2f GB above %s GB pool @ KES %s/GB/month × %d month(s)', $span, $memoryOverGb, $this->trim($memoryPoolGb), $this->trim($memoryRate), $months),
                round($memoryOverGb * $months, 4),
                $memoryRate,
                self::TYPE_MEMORY_OVERAGE,
            );
        }

        $bandwidthPool = $this->bandwidth->bandwidthPoolGb($reseller);
        if ($bandwidthPool > 0) {
            $transferred = $this->bandwidth->transferGbForPeriod($reseller, $from, $to);
            $included = $bandwidthPool * $months;
            $this->appendItem(
                $invoice,
                sprintf('Bandwidth (%s) — %.2f GB transferred by application hosting (included: %d GB)', $span, $transferred, $included),
                1,
                0,
                self::TYPE_BANDWIDTH_USAGE,
            );
            $over = round(max(0, $transferred - $included), 2);
            $rate = $this->bandwidth->bandwidthOverageRate($reseller);
            if ($over > 0 && $rate > 0) {
                $this->appendItem(
                    $invoice,
                    sprintf('Bandwidth overage — %.2f GB above %d GB @ KES %s/GB', $over, $included, $this->trim($rate)),
                    $over,
                    $rate,
                    self::TYPE_BANDWIDTH_OVERAGE,
                );
            }
        }
    }

    /**
     * Mean committed CPU and RAM over the period from the daily snapshots,
     * falling back to what is allocated right now when nothing was recorded.
     *
     * @return array{cpu_cores: float, memory_mb: int}
     */
    public function averageAllocationForPeriod(User $reseller, Carbon $from, Carbon $to): array
    {
        $rows = ResellerDiskUsageSnapshot::query()
            ->where('reseller_id', $reseller->id)
            ->whereBetween('period_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('cpu_cores_allocated')
            ->get(['cpu_cores_allocated', 'memory_mb_allocated']);
        if ($rows->isEmpty()) {
            $now = $this->compute->collectCurrentAllocation($reseller);

            return ['cpu_cores' => (float) $now['cpu_cores'], 'memory_mb' => (int) $now['memory_mb']];
        }

        return [
            'cpu_cores' => round((float) $rows->avg('cpu_cores_allocated'), 2),
            'memory_mb' => (int) round((float) $rows->avg('memory_mb_allocated')),
        ];
    }

    /**
     * @return array{from: Carbon, to: Carbon, months: int}|null
     */
    public function resolveBillingPeriod(User $reseller): ?array
    {
        if (! $reseller->package_expires_at) {
            return null;
        }
        $periodEnd = Carbon::parse($reseller->package_expires_at)->startOfDay();
        $cycle = $reseller->resellerPackage?->billing_cycle ?? 'monthly';
        $months = match ($cycle) {
            'annually', 'yearly', 'annual' => 12,
            'semi-annually', 'semi-annual' => 6,
            'quarterly' => 3,
            default => 1,
        };

        return ['from' => $periodEnd->copy()->subMonths($months), 'to' => $periodEnd, 'months' => $months];
    }

    private function appendItem(Invoice $invoice, string $description, float $quantity, float $unitPrice, string $type): void
    {
        $amount = round($quantity * $unitPrice, 2);
        $breakdown = TaxService::calculateResellerSubscription($amount);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => null,
            'product_type' => $type,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ]);

        $invoice->increment('subtotal', $breakdown['subtotal']);
        $invoice->increment('tax', $breakdown['tax']);
        $invoice->increment('total', $breakdown['total']);
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
