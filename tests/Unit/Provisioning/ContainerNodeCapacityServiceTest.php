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

    public function test_pressure_uses_the_higher_of_live_and_reserved_capacity(): void
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
                'cpu' => 2,
                'memory' => 4096,
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
            'cpu_limit' => 2,
            'memory_limit_mb' => 4096,
        ]);

        $evaluation = app(ContainerNodeCapacityService::class)->evaluate($node->fresh());

        // Live: CPU 20, RAM 25, storage 10. Reserved: CPU 50, RAM 50, storage 40.
        $this->assertSame(50, $evaluation['pressure_percent']);
        $this->assertSame(50, $evaluation['reserved']['ram']);
        $this->assertSame(25, $evaluation['live']['ram']);
        $this->assertFalse(app(ContainerNodeCapacityService::class)->needsScaleOut($node->fresh(), 70));
        $this->assertTrue(app(ContainerNodeCapacityService::class)->needsScaleOut($node->fresh(), 50));
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
}
