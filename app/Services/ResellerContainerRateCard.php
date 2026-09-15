<?php

namespace App\Services;

use App\Models\ResellerProduct;

/**
 * What a unit of application hosting costs the platform, per month, so a
 * reseller's own container plans carry a wholesale price and the margin
 * ledger stops recording every container sale as pure profit. Rates are
 * admin settings; when none is set there is no wholesale figure at all.
 */
class ResellerContainerRateCard
{
    public const SETTING_CPU = 'reseller_container_rate_cpu_core';

    public const SETTING_MEMORY = 'reseller_container_rate_memory_gb';

    public const SETTING_DISK = 'reseller_container_rate_disk_gb';

    public const SETTING_BANDWIDTH = 'reseller_container_rate_bandwidth_gb';

    /**
     * @return array{cpu_core: float, memory_gb: float, disk_gb: float, bandwidth_gb: float}
     */
    public function rates(): array
    {
        return [
            'cpu_core' => (float) setting(self::SETTING_CPU, 0),
            'memory_gb' => (float) setting(self::SETTING_MEMORY, 0),
            'disk_gb' => (float) setting(self::SETTING_DISK, 0),
            'bandwidth_gb' => (float) setting(self::SETTING_BANDWIDTH, 0),
        ];
    }

    public function isConfigured(): bool
    {
        return array_sum($this->rates()) > 0;
    }

    /**
     * Monthly wholesale for a set of plan specs, or null when no rate is set.
     *
     * @param  array{cpu?: float|int|string|null, memory_mb?: int|string|null, disk_gb?: float|int|string|null, bandwidth_gb?: float|int|string|null}  $limits
     */
    public function monthlyWholesaleForLimits(array $limits): ?float
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $rates = $this->rates();
        $cpu = (float) ($limits['cpu'] ?? 0);
        $memoryGb = ((float) ($limits['memory_mb'] ?? 0)) / 1024;
        $diskGb = (float) ($limits['disk_gb'] ?? 0);
        $bandwidthGb = (float) ($limits['bandwidth_gb'] ?? 0);

        return round(
            $cpu * $rates['cpu_core']
            + $memoryGb * $rates['memory_gb']
            + $diskGb * $rates['disk_gb']
            + $bandwidthGb * $rates['bandwidth_gb'],
            2
        );
    }

    public function monthlyWholesaleForListing(ResellerProduct $listing): ?float
    {
        if ($listing->type !== 'container_hosting') {
            return null;
        }
        $limits = $listing->hasContainerResourceLimits()
            ? $listing->containerResourceLimits()
            : $this->limitsFromProduct($listing);
        if ($limits === []) {
            return null;
        }

        return $this->monthlyWholesaleForLimits($limits);
    }

    /**
     * @return array{cpu?: float, memory_mb?: int, disk_gb?: float, bandwidth_gb?: float}
     */
    private function limitsFromProduct(ResellerProduct $listing): array
    {
        $product = $listing->adminProduct;
        if (! $product) {
            return [];
        }
        $product->loadMissing('containerTemplate');
        $included = $product->getIncludedContainerLimits($product->containerTemplate);

        return array_filter([
            'cpu' => $included['cpu'] ?? null,
            'memory_mb' => $included['memory_mb'] ?? null,
            'disk_gb' => $included['disk_gb'] ?? null,
            'bandwidth_gb' => $product->includedBandwidthGb() ?: null,
        ], fn ($v) => $v !== null);
    }
}
