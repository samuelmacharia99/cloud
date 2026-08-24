<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDeploymentCapacityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_quiet_host_accepts_a_plan_whose_sold_cpu_exceeds_the_machine(): void
    {
        $node = $this->containerHost([
            'cpu_cores' => 2,
            'ram_gb' => 8,
            'storage_gb' => 200,
            'cpu_used' => 15,
            'ram_used_gb' => 1,
            'storage_used_gb' => 20,
        ]);
        $service = $this->containerService([
            'cpu' => 4,
            'memory' => 2048,
            'disk' => 40,
        ]);

        $selected = app(ContainerDeploymentService::class)->assertHostHasCapacity($service);

        $this->assertSame($node->id, $selected->id);
    }

    #[Test]
    public function a_cpu_hot_host_is_rejected(): void
    {
        $this->containerHost([
            'cpu_cores' => 8,
            'ram_gb' => 32,
            'storage_gb' => 400,
            'cpu_used' => 95,
            'ram_used_gb' => 4,
            'storage_used_gb' => 40,
        ]);
        $service = $this->containerService([
            'cpu' => 1,
            'memory' => 512,
            'disk' => 10,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('live CPU');

        app(ContainerDeploymentService::class)->assertHostHasCapacity($service);
    }

    #[Test]
    public function sold_plan_memory_is_read_from_memory_mb_and_can_block_a_full_host(): void
    {
        $this->containerHost([
            'cpu_cores' => 8,
            'ram_gb' => 4,
            'storage_gb' => 400,
            'cpu_used' => 10,
            'ram_used_gb' => 3,
            'storage_used_gb' => 20,
        ]);
        $service = $this->containerService([
            'cpu' => 1,
            'memory' => 2048,
            'disk' => 10,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('RAM');

        app(ContainerDeploymentService::class)->assertHostHasCapacity($service);
    }

    #[Test]
    public function resource_share_shrinks_the_ram_footprint_so_a_sibling_site_can_place(): void
    {
        $this->containerHost([
            'cpu_cores' => 8,
            'ram_gb' => 4,
            'storage_gb' => 400,
            'cpu_used' => 10,
            'ram_used_gb' => 2,
            'storage_used_gb' => 20,
        ]);
        $service = $this->containerService([
            'cpu' => 2,
            'memory' => 2048,
            'disk' => 40,
        ], share: 0.2);

        $node = app(ContainerDeploymentService::class)->assertHostHasCapacity($service);

        $this->assertTrue($node->exists);
    }

    #[Test]
    public function oversold_plan_disk_does_not_block_when_live_disk_has_room(): void
    {
        $node = $this->containerHost([
            'cpu_cores' => 8,
            'ram_gb' => 32,
            'storage_gb' => 1800,
            'cpu_used' => 20,
            'ram_used_gb' => 8,
            'storage_used_gb' => 400,
        ]);
        $occupant = $this->containerService([
            'cpu' => 1,
            'memory' => 512,
            'disk' => 1670,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $occupant->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $service = $this->containerService([
            'cpu' => 1,
            'memory' => 696,
            'disk' => 40,
        ], share: 0.16);

        $selected = app(ContainerDeploymentService::class)->assertHostHasCapacity($service);

        $this->assertSame($node->id, $selected->id);
    }

    #[Test]
    public function live_disk_pressure_still_blocks_when_the_volume_is_actually_full(): void
    {
        $this->containerHost([
            'cpu_cores' => 8,
            'ram_gb' => 32,
            'storage_gb' => 1800,
            'cpu_used' => 20,
            'ram_used_gb' => 8,
            'storage_used_gb' => 1615,
        ]);
        $service = $this->containerService([
            'cpu' => 1,
            'memory' => 696,
            'disk' => 40,
        ], share: 0.16);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('live disk');

        app(ContainerDeploymentService::class)->assertHostHasCapacity($service);
    }

    /**
     * @param  array<string, mixed>  $nodeAttrs
     */
    private function containerHost(array $nodeAttrs): Node
    {
        return Node::factory()->containerHost()->create(array_merge([
            'status' => 'online',
            'is_active' => true,
            'container_count' => 0,
        ], $nodeAttrs));
    }

    /**
     * @param  array{cpu: float|int, memory: int, disk: float|int}  $limits
     */
    private function containerService(array $limits, float $share = 1.0): Service
    {
        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 0.5,
            'required_ram_mb' => 256,
            'required_storage_gb' => 5,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => $limits,
        ]);
        $meta = [];
        if ($share > 0 && $share < 1) {
            $meta['resource_share'] = [
                'cpu' => $share,
                'memory' => $share,
            ];
        }

        return Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => null,
            'provisioning_driver_key' => 'container',
            'service_meta' => $meta,
        ]);
    }
}
