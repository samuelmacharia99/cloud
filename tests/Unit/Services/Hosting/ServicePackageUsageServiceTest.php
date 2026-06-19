<?php

namespace Tests\Unit\Services\Hosting;

use App\Services\Hosting\ServicePackageUsageService;
use PHPUnit\Framework\TestCase;

class ServicePackageUsageServiceTest extends TestCase
{
    public function test_metrics_needing_upgrade_at_ninety_percent_threshold(): void
    {
        $service = new ServicePackageUsageService;

        $snapshot = [
            ServicePackageUsageService::METRIC_DISK => [
                'used' => 900,
                'limit' => 1000,
                'percent' => 90.0,
                'unlimited' => false,
                'unit' => 'MB',
            ],
            ServicePackageUsageService::METRIC_BANDWIDTH => [
                'used' => 500,
                'limit' => 1000,
                'percent' => 50.0,
                'unlimited' => false,
                'unit' => 'MB',
            ],
            ServicePackageUsageService::METRIC_DATABASE => [
                'used' => 9,
                'limit' => 10,
                'percent' => 90.0,
                'unlimited' => false,
                'unit' => 'count',
            ],
        ];

        $atRisk = $service->metricsNeedingUpgrade($snapshot, 90);

        $this->assertArrayHasKey(ServicePackageUsageService::METRIC_DISK, $atRisk);
        $this->assertArrayHasKey(ServicePackageUsageService::METRIC_DATABASE, $atRisk);
        $this->assertArrayNotHasKey(ServicePackageUsageService::METRIC_BANDWIDTH, $atRisk);
        $this->assertSame(ServicePackageUsageService::METRIC_DISK, $service->primaryMetric($atRisk));
    }

    public function test_unlimited_metrics_are_ignored_for_upgrade_warnings(): void
    {
        $service = new ServicePackageUsageService;

        $snapshot = [
            ServicePackageUsageService::METRIC_BANDWIDTH => [
                'used' => 50000,
                'limit' => null,
                'percent' => null,
                'unlimited' => true,
                'unit' => 'MB',
            ],
        ];

        $this->assertSame([], $service->metricsNeedingUpgrade($snapshot, 90));
    }
}
