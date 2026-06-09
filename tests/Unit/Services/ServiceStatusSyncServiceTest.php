<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Services\ServiceStatusSyncService;
use App\Support\ServiceLiveStatusResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceStatusSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceStatusSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = app(ServiceStatusSyncService::class);
    }

    public function test_detects_mismatch_when_platform_active_but_live_suspended(): void
    {
        $service = $this->makeService(ServiceStatus::Active);

        $result = new ServiceLiveStatusResult(
            status: 'suspended',
            label: 'Suspended on DirectAdmin',
            source: 'directadmin',
        );

        $this->assertTrue($this->sync->detectMismatch($service, $result));
    }

    public function test_no_mismatch_when_platform_matches_live(): void
    {
        $service = $this->makeService(ServiceStatus::Suspended);

        $result = new ServiceLiveStatusResult(
            status: 'suspended',
            label: 'Container stopped',
            source: 'container',
        );

        $this->assertFalse($this->sync->detectMismatch($service, $result));
    }

    public function test_unknown_live_status_does_not_flag_mismatch(): void
    {
        $service = $this->makeService(ServiceStatus::Active);

        $result = ServiceLiveStatusResult::unknown('API timeout', 'directadmin');

        $this->assertFalse($this->sync->detectMismatch($service, $result));
    }

    public function test_persists_live_status_fields_on_sync_for_pending_container(): void
    {
        $product = Product::factory()->create(['type' => 'container_hosting', 'provisioning_driver_key' => 'container']);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'provisioning_driver_key' => 'container',
        ]);

        $result = $this->sync->sync($service);

        $service->refresh();

        $this->assertSame('pending', $result->status);
        $this->assertSame('pending', $service->live_status);
        $this->assertSame('container', $service->live_status_source);
        $this->assertNotNull($service->live_status_checked_at);
        $this->assertFalse($service->live_status_mismatch);
    }

    private function makeService(ServiceStatus $status): Service
    {
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        return Service::factory()->create([
            'product_id' => $product->id,
            'status' => $status,
            'provisioning_driver_key' => 'directadmin',
        ]);
    }
}
