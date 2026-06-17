<?php

namespace Tests\Unit\Services;

use App\Enums\TicketHandledBy;
use App\Models\User;
use App\Services\TicketRoutingService;
use Tests\TestCase;

class TicketRoutingServiceTest extends TestCase
{
    public function test_attributes_for_platform_customer(): void
    {
        $customer = new User(['reseller_id' => null, 'is_reseller' => false]);
        $service = app(TicketRoutingService::class);

        $attrs = $service->attributesForCreator($customer);

        $this->assertNull($attrs['reseller_id']);
        $this->assertSame(TicketHandledBy::Platform->value, $attrs['handled_by']);
    }

    public function test_attributes_for_reseller_customer(): void
    {
        $customer = new User(['reseller_id' => 42, 'is_reseller' => false]);
        $service = app(TicketRoutingService::class);

        $attrs = $service->attributesForCreator($customer);

        $this->assertSame(42, $attrs['reseller_id']);
        $this->assertSame(TicketHandledBy::Reseller->value, $attrs['handled_by']);
    }

    public function test_attributes_for_reseller_creator(): void
    {
        $reseller = new User(['is_reseller' => true]);
        $service = app(TicketRoutingService::class);

        $attrs = $service->attributesForCreator($reseller);

        $this->assertNull($attrs['reseller_id']);
        $this->assertSame(TicketHandledBy::Platform->value, $attrs['handled_by']);
    }

    public function test_attributes_for_admin_creator_on_reseller_customer(): void
    {
        $customer = new User(['reseller_id' => 42, 'is_reseller' => false]);
        $service = app(TicketRoutingService::class);

        $attrs = $service->attributesForAdminCreator($customer);

        $this->assertSame(42, $attrs['reseller_id']);
        $this->assertSame(TicketHandledBy::Platform->value, $attrs['handled_by']);
    }
}
