<?php

namespace Tests\Unit\Services\Hosting;

use App\Services\Hosting\ServicePackageLimitEnforcementService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use PHPUnit\Framework\TestCase;

class ServicePackageLimitEnforcementServiceTest extends TestCase
{
    private ServicePackageLimitEnforcementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ServicePackageLimitEnforcementService(
            new ServicePackageUsageService,
            $this->createMock(ServiceOverdueEnforcementService::class),
            $this->createMock(ProvisioningService::class),
        );
    }

    public function test_detects_over_limit_at_one_hundred_percent(): void
    {
        $this->assertTrue($this->service->isMetricOverLimit([
            'used' => 1024,
            'limit' => 1024,
            'percent' => 100,
            'unlimited' => false,
            'unit' => 'MB',
        ], 100));

        $this->assertFalse($this->service->isMetricOverLimit([
            'used' => 1023,
            'limit' => 1024,
            'percent' => 99.9,
            'unlimited' => false,
            'unit' => 'MB',
        ], 100));
    }

    public function test_metrics_over_limit_includes_database_at_capacity(): void
    {
        $over = $this->service->metricsOverLimit([
            'disk' => ['used' => 100, 'limit' => 1000, 'percent' => 10, 'unlimited' => false, 'unit' => 'MB'],
            'bandwidth' => ['used' => 950, 'limit' => 1000, 'percent' => 95, 'unlimited' => false, 'unit' => 'MB'],
            'database' => ['used' => 5, 'limit' => 5, 'percent' => 100, 'unlimited' => false, 'unit' => 'count'],
        ], 100);

        $this->assertArrayHasKey('database', $over);
        $this->assertArrayNotHasKey('disk', $over);
        $this->assertArrayNotHasKey('bandwidth', $over);
    }
}
