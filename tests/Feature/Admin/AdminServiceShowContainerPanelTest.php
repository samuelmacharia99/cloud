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

class AdminServiceShowContainerPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_container_service_when_template_lives_on_service_meta(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'required_cpu_cores' => 1,
            'required_ram_mb' => 1024,
            'required_storage_gb' => 10,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => null,
            'name' => 'TIER 1',
        ]);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => 'TIER 1',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'language_slug' => 'nodejs',
                'container_template_id' => $template->id,
            ],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-nodejs',
            'assigned_port' => 30022,
        ]);

        $this->assertNull($service->fresh()->product?->containerTemplate);

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Resource Allocation')
            ->assertSee('1024MB')
            ->assertSee('10GB');
    }
}
