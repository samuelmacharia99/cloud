<?php

namespace App\Services\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\CustomerProject;
use App\Services\ContainerOverageBillingService;
use App\Services\ProjectBandwidthBillingService;
use Illuminate\Support\Carbon;

class ProjectConsumptionService
{
    public const WINDOW_HOURS = 6;

    public function __construct(
        private ContainerOverageBillingService $overage,
        private ProjectBandwidthBillingService $bandwidth,
    ) {}

    /**
     * Return the stored 6-hour snapshot, refreshing when missing or stale.
     *
     * @return array<string, mixed>|null
     */
    public function forDisplay(CustomerProject $project): ?array
    {
        if ($project->liveApplicationHostingServices()->isEmpty()) {
            return is_array($project->consumption_snapshot) ? $project->consumption_snapshot : null;
        }

        $at = $project->consumption_snapshot_at;
        if ($at instanceof Carbon
            && $at->greaterThan(now()->subHours(self::WINDOW_HOURS))
            && is_array($project->consumption_snapshot)
        ) {
            return $project->consumption_snapshot;
        }

        return $this->refresh($project);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(CustomerProject $project): array
    {
        $snapshot = $this->compute($project);

        $project->update([
            'consumption_snapshot' => $snapshot,
            'consumption_snapshot_at' => now(),
        ]);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(CustomerProject $project): array
    {
        $project->loadMissing([
            'services.product.containerTemplate',
            'services.containerDeployment',
            'billingService.product.containerTemplate',
            'billingService.containerDeployment',
        ]);

        $to = now();
        $from = $to->copy()->subHours(self::WINDOW_HOURS);
        $limits = $project->includedPlanLimits();
        $anchor = $project->resolvedBillingService();
        $includedBandwidthGb = $anchor?->product
            ? $this->bandwidth->includedBandwidthGb($anchor->product)
            : 0.0;

        $cpuCores = 0.0;
        $memoryMb = 0.0;
        $diskGb = 0.0;
        $transferBytes = 0;
        $sampleCount = 0;
        $deploymentCount = 0;

        foreach ($project->liveApplicationHostingServices() as $service) {
            $deployment = $service->containerDeployment;
            if (! $deployment) {
                continue;
            }

            $deploymentCount++;
            $cpuCores += ContainerMetric::averageCpuPercent($deployment, $from, $to) / 100;
            $memoryMb += $this->averageMemoryMb($deployment, $from, $to);
            $diskGb += ContainerMetric::averageDiskUsedGb($deployment, $from, $to);
            $transferBytes += ContainerMetric::transferBytesForPeriod($deployment, $from, $to);
            $sampleCount += $this->usageSampleCount($deployment, $from, $to);
        }

        $billingTransferBytes = 0;
        $billingFrom = null;
        $billingTo = null;
        if ($anchor) {
            $period = $this->overage->resolveBillingPeriod($anchor);
            if ($period) {
                $billingFrom = $period['from'];
                $billingTo = $period['to'];
                foreach ($project->liveApplicationHostingServices() as $service) {
                    if ($service->containerDeployment) {
                        $billingTransferBytes += ContainerMetric::transferBytesForPeriod(
                            $service->containerDeployment,
                            $period['from'],
                            $period['to'],
                        );
                    }
                }
            }
        }

        $includedCpu = (float) ($limits['cpu'] ?? 0);
        $includedMemoryMb = (int) ($limits['memory_mb'] ?? 0);
        $includedDiskGb = (float) ($limits['disk_gb'] ?? 0);

        return [
            'window_hours' => self::WINDOW_HOURS,
            'sampled_at' => $to->toIso8601String(),
            'has_samples' => $sampleCount > 0,
            'deployment_count' => $deploymentCount,
            'cpu_cores' => round($cpuCores, 3),
            'memory_mb' => (int) round($memoryMb),
            'disk_gb' => round($diskGb, 2),
            'transfer_bytes' => $transferBytes,
            'billing_transfer_bytes' => $billingTransferBytes,
            'billing_from' => $billingFrom?->toIso8601String(),
            'billing_to' => $billingTo?->toIso8601String(),
            'included' => [
                'cpu' => $includedCpu,
                'memory_mb' => $includedMemoryMb,
                'disk_gb' => $includedDiskGb,
                'bandwidth_gb' => $includedBandwidthGb,
            ],
            'percent' => [
                'cpu' => $this->percent($cpuCores, $includedCpu),
                'memory' => $this->percent($memoryMb, $includedMemoryMb),
                'disk' => $this->percent($diskGb, $includedDiskGb),
                'bandwidth' => $this->percent($billingTransferBytes / (1024 ** 3), $includedBandwidthGb),
            ],
        ];
    }

    private function averageMemoryMb(ContainerDeployment $deployment, Carbon $from, Carbon $to): float
    {
        $avg = ContainerMetric::query()
            ->where('container_deployment_id', $deployment->id)
            ->usageSamples()
            ->inBillingPeriod($from, $to)
            ->avg('memory_used_mb');

        return (float) ($avg ?? 0);
    }

    private function usageSampleCount(ContainerDeployment $deployment, Carbon $from, Carbon $to): int
    {
        return (int) ContainerMetric::query()
            ->where('container_deployment_id', $deployment->id)
            ->usageSamples()
            ->inBillingPeriod($from, $to)
            ->count();
    }

    private function percent(float $used, float $included): ?float
    {
        if ($included <= 0) {
            return null;
        }

        return round(($used / $included) * 100, 1);
    }
}
