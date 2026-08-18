<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminContainerNodeAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_host_show_explains_live_versus_sold_capacity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $node = Node::factory()->containerHost()->create([
            'name' => 'Talksasa-vm1',
            'cpu_cores' => 4,
            'ram_gb' => 16,
            'storage_gb' => 200,
            'cpu_used' => 16,
            'ram_used_gb' => 2,
            'storage_used_gb' => 20,
            'last_heartbeat_at' => now()->subMinute(),
            'status' => 'online',
        ]);
        $this->provisionRuntime($node, 'TIER 1', [
            'cpu' => 8,
            'memory' => 1024,
            'disk' => 20,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.nodes.show', $node))
            ->assertOk()
            ->assertSee('Live vs sold capacity')
            ->assertSee('Placement uses')
            ->assertSee('Plan CPU is oversold')
            ->assertDontSee('Scale-out pressure');
    }

    public function test_directadmin_node_show_does_not_render_container_analytics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $node = Node::factory()->directAdmin()->create([
            'last_heartbeat_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.nodes.show', $node))
            ->assertOk()
            ->assertDontSee('Live vs sold capacity')
            ->assertDontSee('Top consumers (24h)');
    }

    /**
     * @param  array{cpu?: float, memory?: int, disk?: float}  $limits
     */
    private function provisionRuntime(Node $node, string $name, array $limits): Service
    {
        $template = ContainerTemplate::factory()->create();
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => $limits['cpu'] ?? 1,
                'memory' => $limits['memory'] ?? 1024,
                'disk' => $limits['disk'] ?? 10,
            ],
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => $name,
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return $service;
    }
}
