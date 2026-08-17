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
            'name' => 'Node.js',
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
                'source_repo_url' => 'animated123/apigateway',
                'source_repo_branch' => 'main',
                'env_values' => [
                    'JWT_SECRET' => 'super-secret-jwt-xyz-193',
                ],
                'auto_deploy_secret_encrypted' => 'enc-secret-should-not-leak',
            ],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-nodejs',
            'assigned_port' => 30022,
            'domain' => 'gateway.example.test',
        ]);

        $this->assertNull($service->fresh()->product?->containerTemplate);

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Application runtime')
            ->assertSee('Resource Allocation')
            ->assertSee('1024MB')
            ->assertSee('10GB')
            ->assertSee('Node.js')
            ->assertSee('Open site')
            ->assertSee('https://gateway.example.test')
            ->assertSee('animated123/apigateway')
            ->assertSee('Environment variables')
            ->assertDontSee('Service Metadata')
            ->assertDontSee('super-secret-jwt-xyz-193')
            ->assertDontSee('enc-secret-should-not-leak');
    }

    public function test_admin_sees_empty_runtime_state_when_container_is_not_provisioned(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $template = ContainerTemplate::factory()->create(['slug' => 'nodejs', 'name' => 'Node.js']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => null,
            'name' => 'TIER 1',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'TIER 1',
            'status' => 'pending',
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'language_slug' => 'nodejs',
                'container_template_id' => $template->id,
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Application runtime')
            ->assertSee('No container has been provisioned for this service yet.');
    }
}
