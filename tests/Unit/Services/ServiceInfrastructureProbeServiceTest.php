<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\ServiceInfrastructureProbeService;
use Tests\TestCase;

class ServiceInfrastructureProbeServiceTest extends TestCase
{
    public function test_detects_missing_directadmin_account_from_cached_live_status(): void
    {
        $service = Service::make([
            'external_reference' => 'devkiste',
            'provisioning_driver_key' => 'directadmin',
            'live_status' => 'terminated',
            'live_status_label' => 'Account not found on DirectAdmin',
            'service_meta' => ['username' => 'devkiste'],
        ]);
        $service->id = 51;

        $this->assertTrue(app(ServiceInfrastructureProbeService::class)->directAdminAccountMissing($service));
        $this->assertTrue(app(ServiceInfrastructureProbeService::class)->infrastructureAlreadyAbsent($service));
    }

    public function test_treats_missing_username_as_absent_infrastructure(): void
    {
        $service = Service::make([
            'provisioning_driver_key' => 'directadmin',
            'status' => ServiceStatus::Suspended,
            'service_meta' => [],
        ]);
        $service->id = 52;

        $this->assertTrue(app(ServiceInfrastructureProbeService::class)->directAdminAccountMissing($service));
    }
}
