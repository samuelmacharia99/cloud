<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerNodeCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerNodeCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pressure_ignores_soft_oversold_cpu_and_ram_reservations(): void
    {
        $node = Node::factory()->containerHost()->create([
            'cpu_cores' => 4,
            'ram_gb' => 8,
            'storage_gb' => 100,
            'cpu_used' => 20,
            'ram_used_gb' => 2,
            'storage_used_gb' => 10,
            'status' => 'online',
            'is_active' => true,
        ]);

        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 1,
            'required_ram_mb' => 1024,
            'required_storage_gb' => 10,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => 8,
                'memory' => 16384,
                'disk' => 40,
            ],
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'cpu_limit' => 8,
            'memory_limit_mb' => 16384,
        ]);

        $evaluation = app(ContainerNodeCapacityService::class)->evaluate($node->fresh());

        // Live: CPU 20, RAM 25, storage 10. Sold CPU/RAM/disk stay informational.
        $this->assertSame(25, $evaluation['pressure_percent']);
        $this->assertSame(200, $evaluation['reserved']['cpu']);
        $this->assertSame(8.0, $evaluation['reserved_absolute']['cpu_cores']);
        $this->assertSame(16.0, $evaluation['reserved_absolute']['ram_gb']);
        $this->assertSame(40.0, $evaluation['reserved_absolute']['storage_gb']);
        $this->assertSame(200, $evaluation['reserved']['ram']);
        $this->assertSame(40, $evaluation['reserved']['storage']);
        $this->assertSame(25, $evaluation['live']['ram']);
        $this->assertContains('live RAM', $evaluation['drivers']);
        $this->assertNotContains('reserved storage', $evaluation['drivers']);
        $this->assertFalse(app(ContainerNodeCapacityService::class)->needsScaleOut($node->fresh(), 70));
        $this->assertTrue(app(ContainerNodeCapacityService::class)->needsScaleOut($node->fresh(), 25));
    }

    public function test_live_pressure_alone_can_trigger_scale_out(): void
    {
        $node = Node::factory()->containerHost()->create([
            'cpu_cores' => 8,
            'ram_gb' => 16,
            'storage_gb' => 200,
            'cpu_used' => 10,
            'ram_used_gb' => 12,
            'storage_used_gb' => 20,
            'status' => 'online',
            'is_active' => true,
        ]);

        $evaluation = app(ContainerNodeCapacityService::class)->evaluate($node);

        $this->assertSame(75, $evaluation['pressure_percent']);
        $this->assertContains('live RAM', $evaluation['drivers']);
        $this->assertTrue(app(ContainerNodeCapacityService::class)->needsScaleOut($node, 70));
    }

    public function test_quiet_host_with_high_sold_cpu_does_not_alert(): void
    {
        $node = Node::factory()->containerHost()->create([
            'cpu_cores' => 12,
            'ram_gb' => 62,
            'storage_gb' => 1800,
            'cpu_used' => 16,
            'ram_used_gb' => 9,
            'storage_used_gb' => 150,
            'status' => 'online',
            'is_active' => true,
        ]);

        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 1,
            'required_ram_mb' => 1024,
            'required_storage_gb' => 20,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => 6,
                'memory' => 6144,
                'disk' => 40,
            ],
        ]);

        for ($i = 0; $i < 22; $i++) {
            $service = Service::factory()->create([
                'product_id' => $product->id,
                'node_id' => $node->id,
            ]);
            ContainerDeployment::factory()->create([
                'service_id' => $service->id,
                'node_id' => $node->id,
                'status' => 'running',
                'cpu_limit' => 6,
                'memory_limit_mb' => 6144,
            ]);
        }

        $evaluation = app(ContainerNodeCapacityService::class)->evaluate($node->fresh());

        // Live CPU 16%; sold disk ~49% is informational only.
        $this->assertSame(16, $evaluation['pressure_percent']);
        $this->assertGreaterThan(100, $evaluation['reserved']['cpu']);
        $this->assertContains('live CPU', $evaluation['drivers']);
        $this->assertNotContains('reserved storage', $evaluation['drivers']);
        $this->assertFalse(app(ContainerNodeCapacityService::class)->needsScaleOut($node->fresh(), 70));
    }
}
