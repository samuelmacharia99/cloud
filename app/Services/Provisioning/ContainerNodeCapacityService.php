<?php

namespace App\Services\Provisioning;

use App\Models\Node;

/**
 * Measures application-host pressure for elastic placement and scale-out alerts.
 *
 * Scale-out pressure follows live host utilization (what Admin → Nodes shows) plus
 * sold disk reservations. Plan CPU/RAM allowances are soft and intentionally
 * oversubscribe, so they are reported for operators but do not drive the alert.
 */
class ContainerNodeCapacityService
{
    /**
     * @return array{
     *     pressure_percent: int,
     *     live: array{cpu: int, ram: int, storage: int},
     *     reserved: array{cpu: int, ram: int, storage: int},
     *     drivers: list<string>,
     *     deployment_count: int
     * }
     */
    public function evaluate(Node $node): array
    {
        $node->loadMissing([
            'containerDeployments' => fn ($query) => $query
                ->where('status', '!=', 'terminated')
                ->with('service.product.containerTemplate'),
        ]);

        $reservedCpu = 0.0;
        $reservedRamGb = 0.0;
        $reservedStorageGb = 0.0;

        foreach ($node->containerDeployments as $deployment) {
            $limits = $deployment->service?->product?->getIncludedContainerLimits(
                $deployment->service?->product?->containerTemplate,
                $deployment
            ) ?? [
                'cpu' => (float) ($deployment->cpu_limit ?? 0),
                'memory_mb' => (int) ($deployment->memory_limit_mb ?? 0),
                'disk_gb' => 0,
            ];

            $reservedCpu += (float) $limits['cpu'];
            $reservedRamGb += (float) $limits['memory_mb'] / 1024;
            $reservedStorageGb += (float) $limits['disk_gb'];
        }

        $liveCpu = min(100, max(0, (int) $node->cpu_used));
        $liveRam = $node->ram_gb > 0
            ? (int) round(($node->ram_used_gb / $node->ram_gb) * 100)
            : 0;
        $liveStorage = $node->storage_gb > 0
            ? (int) round(($node->storage_used_gb / $node->storage_gb) * 100)
            : 0;

        $reservedCpuPercent = $node->cpu_cores > 0
            ? (int) round(($reservedCpu / $node->cpu_cores) * 100)
            : 0;
        $reservedRamPercent = $node->ram_gb > 0
            ? (int) round(($reservedRamGb / $node->ram_gb) * 100)
            : 0;
        $reservedStoragePercent = $node->storage_gb > 0
            ? (int) round(($reservedStorageGb / $node->storage_gb) * 100)
            : 0;

        // Soft CPU/RAM oversubscription is expected under elastic hosting.
        $scores = [
            'live CPU' => $liveCpu,
            'live RAM' => $liveRam,
            'live storage' => $liveStorage,
            'reserved storage' => $reservedStoragePercent,
        ];
        $pressure = max(0, ...array_values($scores));
        $drivers = array_keys(array_filter(
            $scores,
            fn (int $value) => $value >= $pressure && $pressure > 0
        ));

        return [
            'pressure_percent' => $pressure,
            'live' => [
                'cpu' => $liveCpu,
                'ram' => $liveRam,
                'storage' => $liveStorage,
            ],
            'reserved' => [
                'cpu' => $reservedCpuPercent,
                'ram' => $reservedRamPercent,
                'storage' => $reservedStoragePercent,
            ],
            'drivers' => $drivers,
            'deployment_count' => $node->containerDeployments->count(),
        ];
    }

    public function needsScaleOut(Node $node, ?int $thresholdPercent = null): bool
    {
        $threshold = $thresholdPercent
            ?? (int) config('containers.elastic_resources.scale_out_threshold_percent', 70);

        return $this->evaluate($node)['pressure_percent'] >= max(1, min(99, $threshold));
    }
}
