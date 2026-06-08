<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerEnforcementService;
use App\Services\ServiceEnforcementInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceEnforcementInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_disk_overquota_suspension(): void
    {
        $product = Product::factory()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_DISK_OVERQUOTA,
                'disk_used_mb' => 1100,
                'disk_limit_mb' => 1024,
            ],
        ]);

        $insight = app(ServiceEnforcementInsightService::class)->forService($service);

        $this->assertSame('Disk quota exceeded', $insight['suspension_label']);
        $this->assertTrue($insight['is_suspended']);
        $this->assertNotEmpty($insight['alerts']);
    }

    public function test_collects_customer_service_alerts(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_INVOICE_OVERDUE,
            ],
        ]);

        $alerts = app(ServiceEnforcementInsightService::class)
            ->alertsForCustomerServices(collect([$service]));

        $this->assertCount(1, $alerts);
        $this->assertSame($service->id, $alerts[0]['service_id']);
    }
}
