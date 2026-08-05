<?php

namespace Tests\Unit\Models;

use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerDirectAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerServiceSlotCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_slots_use_directadmin_hosted_user_count_when_available(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Slot Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_services' => 10,
            'max_users' => 20,
            'price' => 1000,
            'active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'directadmin_username' => 'res_acme',
        ]);

        Service::factory()->count(2)->create([
            'reseller_id' => $reseller->id,
            'status' => 'active',
        ]);

        $this->mock(ResellerDirectAdminService::class, function ($mock) {
            $mock->shouldReceive('fetchHostedUserCount')->andReturn(7);
        });

        $this->assertSame(7, $reseller->getManagedActiveServicesCount());
        $this->assertSame(
            ['count' => 7, 'source' => 'directadmin'],
            $reseller->getServiceSlotCountBreakdown()
        );
        $this->assertFalse($reseller->isAtServiceLimit());
    }

    public function test_service_slots_fall_back_to_active_portal_services_without_da(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Slot Test Portal',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_services' => 3,
            'max_users' => 20,
            'price' => 1000,
            'active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'directadmin_username' => null,
        ]);

        Service::factory()->count(3)->create([
            'reseller_id' => $reseller->id,
            'status' => 'active',
        ]);
        Service::factory()->create([
            'reseller_id' => $reseller->id,
            'status' => 'suspended',
        ]);

        $this->assertSame(
            ['count' => 3, 'source' => 'portal'],
            $reseller->getServiceSlotCountBreakdown()
        );
        $this->assertTrue($reseller->isAtServiceLimit());
    }
}
