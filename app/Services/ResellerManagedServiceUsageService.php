<?php

namespace App\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Service;
use Illuminate\Support\Carbon;

class ResellerManagedServiceUsageService
{
    /**
     * Read-only allocated plan specs and latest consumption for reseller operators.
     *
     * @param  iterable<int, Service>  $services
     * @return array<int, array{allocated: ?array, consumed: ?array}>
     */
    public function forServices(iterable $services): array
    {
        $map = [];

        foreach ($services as $service) {
            $map[$service->id] = $this->forService($service);
        }

        return $map;
    }

    /**
     * @return array{allocated: ?array{cpu: float, memory_mb: int, disk_gb: float, bandwidth_gb: ?float}, consumed: ?array{disk_gb: ?float, memory_mb: ?int, cpu_percent: ?float, transfer_gb: float, sampled_at: ?Carbon}}
     */
    public function forService(Service $service): array
    {
        $product = $service->product;
        $deployment = $service->containerDeployment;
        $allocated = null;

        if ($product && $service->isContainerHosting()) {
            $limits = $product->getIncludedContainerLimits(
                $product->containerTemplate,
                $deployment,
            );

            $allocated = [
                'cpu' => (float) $limits['cpu'],
                'memory_mb' => (int) $limits['memory_mb'],
                'disk_gb' => (float) $limits['disk_gb'],
                'bandwidth_gb' => $product->includedBandwidthGb(),
            ];
        }

        $consumed = null;

        if ($deployment) {
            $latest = $deployment->relationLoaded('latestRecordedMetric')
                ? $deployment->latestRecordedMetric
                : $deployment->latestMetric();

            if ($latest) {
                $consumed = [
                    'disk_gb' => $latest->disk_used_gb !== null ? round((float) $latest->disk_used_gb, 2) : null,
                    'memory_mb' => $latest->memory_used_mb !== null ? (int) $latest->memory_used_mb : null,
                    'cpu_percent' => $latest->cpu_percentage !== null ? (float) $latest->cpu_percentage : null,
                    'transfer_gb' => $this->transferGbForWindow($deployment),
                    'sampled_at' => $latest->recorded_at,
                ];
            }
        }

        return [
            'allocated' => $allocated,
            'consumed' => $consumed,
        ];
    }

    private function transferGbForWindow(ContainerDeployment $deployment): float
    {
        $bytes = ContainerMetric::transferBytesForPeriod(
            $deployment,
            now()->subDays(30),
            now(),
        );

        return round($bytes / 1024 / 1024 / 1024, 2);
    }
}
