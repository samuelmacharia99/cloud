<?php

namespace App\Services\Hosting;

use App\Models\Service;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ServicePackageUsageWarningService
{
    public function __construct(
        private ServicePackageUsageService $usage,
        private NotificationService $notifications,
        private CustomerHostingUpgradeService $upgrades,
    ) {}

    /**
     * @return array{skipped: bool, notified: bool, at_risk: list<string>}
     */
    public function processService(Service $service): array
    {
        $snapshot = $this->usage->fetchLiveUsage($service);
        if ($snapshot === null) {
            return ['skipped' => true, 'notified' => false, 'at_risk' => []];
        }

        $this->usage->persistSnapshot($service, $snapshot, $this->usage->lastDashboard());
        $service->refresh();

        $atRisk = $this->usage->metricsNeedingUpgrade($snapshot);

        if ($atRisk === []) {
            $this->clearWarningState($service);

            return ['skipped' => false, 'notified' => false, 'at_risk' => []];
        }

        if (! $this->shouldNotify($service)) {
            return ['skipped' => false, 'notified' => false, 'at_risk' => array_keys($atRisk)];
        }

        $recommended = $this->upgrades->recommendedUpgrade(
            $service,
            $service->user,
            $this->usage->primaryMetric($atRisk),
        );

        try {
            $this->notifications->notifyHostingPackageUsageWarning($service, $atRisk, $recommended);
            $this->markWarningSent($service);
        } catch (\Throwable $e) {
            Log::error('Failed to send hosting package usage warning', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);

            return ['skipped' => false, 'notified' => false, 'at_risk' => array_keys($atRisk)];
        }

        return ['skipped' => false, 'notified' => true, 'at_risk' => array_keys($atRisk)];
    }

    /**
     * @return array{checked: int, notified: int, skipped: int, at_risk: int}
     */
    public function run(): array
    {
        $checked = 0;
        $notified = 0;
        $skipped = 0;
        $atRisk = 0;

        foreach ($this->usage->monitorableServicesQuery()->cursor() as $service) {
            $checked++;
            $result = $this->processService($service);

            if ($result['skipped']) {
                $skipped++;
            }

            if ($result['notified']) {
                $notified++;
            }

            if ($result['at_risk'] !== []) {
                $atRisk++;
            }
        }

        return [
            'checked' => $checked,
            'notified' => $notified,
            'skipped' => $skipped,
            'at_risk' => $atRisk,
        ];
    }

    private function shouldNotify(Service $service): bool
    {
        $meta = $service->service_meta[ServicePackageUsageService::META_KEY] ?? [];
        $sentAt = $meta['warning_sent_at'] ?? null;

        if (! $sentAt) {
            return true;
        }

        return now()->parse($sentAt)->addDays(7)->isPast();
    }

    private function markWarningSent(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        $usageMeta = $meta[ServicePackageUsageService::META_KEY] ?? [];
        $usageMeta['warning_sent_at'] = now()->toIso8601String();
        $meta[ServicePackageUsageService::META_KEY] = $usageMeta;
        $service->update(['service_meta' => $meta]);
    }

    private function clearWarningState(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        $usageMeta = $meta[ServicePackageUsageService::META_KEY] ?? [];

        if ($usageMeta === [] || ! isset($usageMeta['warning_sent_at'])) {
            return;
        }

        $snapshot = $usageMeta;
        if (! $this->usage->allMetricsBelowClearThreshold($snapshot)) {
            return;
        }

        unset($usageMeta['warning_sent_at']);
        $meta[ServicePackageUsageService::META_KEY] = $usageMeta;
        $service->update(['service_meta' => $meta]);
    }
}
