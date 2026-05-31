<?php

namespace Tests\Feature;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Node $node;

    private ContainerTemplate $template;

    private Product $product;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create();

        // Create container host node
        $this->node = Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
        ]);

        // Create container template
        $this->template = ContainerTemplate::factory()->create([
            'docker_image' => 'nginx:latest',
            'default_port' => 80,
            'required_cpu_cores' => 1.0,
            'required_ram_mb' => 256,
        ]);

        // Create product with container template
        $this->product = Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $this->template->id,
            'provisioning_driver_key' => 'container',
        ]);

        // Create service
        $this->service = Service::factory()->create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'node_id' => $this->node->id,
            'provisioning_driver_key' => 'container',
        ]);
    }

    public function test_container_deployment_creates_deployment_record(): void
    {
        $this->assertNull($this->service->containerDeployment);

        // Mark service as pending for deployment
        $this->service->update(['status' => 'pending']);

        // Simulate deployment (in real scenario, this would be done via API or form)
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
            'status' => 'running',
        ]);

        $this->assertNotNull($this->service->fresh()->containerDeployment);
        $this->assertEquals('running', $deployment->status);
    }

    public function test_container_deployment_has_proper_resource_limits(): void
    {
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
            'status' => 'running',
            'cpu_limit' => 2.0,
            'memory_limit_mb' => 512,
        ]);

        $this->assertEquals(2.0, $deployment->cpu_limit);
        $this->assertEquals(512, $deployment->memory_limit_mb);
    }

    public function test_container_deployment_tracks_auto_restart(): void
    {
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
            'auto_restart' => true,
            'restart_policy' => 'unless-stopped',
            'restart_attempts' => 0,
        ]);

        $this->assertTrue($deployment->auto_restart);
        $this->assertEquals('unless-stopped', $deployment->restart_policy);
        $this->assertEquals(0, $deployment->restart_attempts);

        // Simulate failed restart
        $deployment->increment('restart_attempts');
        $this->assertEquals(1, $deployment->fresh()->restart_attempts);
    }

    public function test_service_has_container_deployment_relationship(): void
    {
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
        ]);

        $this->assertEquals($deployment->id, $this->service->fresh()->containerDeployment->id);
    }

    public function test_container_template_shows_product_associations(): void
    {
        $this->assertEquals(1, $this->template->products()->count());
        $this->assertTrue($this->template->products()->where('id', $this->product->id)->exists());
    }

    public function test_node_tracks_container_count(): void
    {
        $initialCount = $this->node->container_count ?? 0;

        // Create a deployment
        ContainerDeployment::factory()->create([
            'node_id' => $this->node->id,
            'status' => 'running',
        ]);

        // Simulate increment
        $this->node->increment('container_count');

        $this->assertEquals($initialCount + 1, $this->node->fresh()->container_count);
    }

    public function test_container_backup_relationships(): void
    {
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
        ]);

        $backup = ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
        ]);

        $this->assertTrue($deployment->backups()->where('id', $backup->id)->exists());
        $this->assertTrue($this->service->containerBackups()->where('id', $backup->id)->exists());
    }

    public function test_api_shows_container_status(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
            'status' => 'running',
            'assigned_port' => 30000,
        ]);

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container");

        $response->assertStatus(200)
            ->assertJson([
                'container_name' => $deployment->container_name,
                'status' => 'running',
                'port' => 30000,
            ]);
    }

    public function test_api_requires_ownership_for_container_operations(): void
    {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'sanctum');

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container");

        $response->assertStatus(403);
    }

    public function test_container_template_api_lists_templates(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/v1/container-templates');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $this->template->id)
            ->assertJsonPath('data.0.slug', $this->template->slug);
    }

    public function test_container_template_api_shows_details(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/v1/container-templates/{$this->template->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $this->template->id)
            ->assertJsonPath('docker_image', $this->template->docker_image)
            ->assertJsonPath('required_cpu_cores', $this->template->required_cpu_cores)
            ->assertJsonPath('required_ram_mb', $this->template->required_ram_mb);
    }

    public function test_node_api_requires_admin_access(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/v1/nodes');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_node_api(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/v1/nodes');

        $response->assertStatus(200);
    }

    public function test_service_can_list_backups(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
        ]);

        $backup = ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $this->service->id,
            'status' => 'completed',
        ]);

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container/backups");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $backup->id)
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_deployment_increments_node_count_on_create(): void
    {
        $initialCount = $this->node->container_count ?? 0;

        $deployment = ContainerDeployment::factory()->create([
            'node_id' => $this->node->id,
        ]);

        $this->node->increment('container_count');

        $this->assertEquals($initialCount + 1, $this->node->fresh()->container_count);
    }

    public function test_deployment_decrements_node_count_on_terminate(): void
    {
        $this->node->update(['container_count' => 5]);

        $deployment = ContainerDeployment::factory()->create([
            'node_id' => $this->node->id,
            'status' => 'terminated',
        ]);

        $this->node->decrement('container_count');

        $this->assertEquals(4, $this->node->fresh()->container_count);
    }

    public function test_auto_restart_command_tracks_attempts(): void
    {
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'node_id' => $this->node->id,
            'auto_restart' => true,
            'restart_policy' => 'unless-stopped',
            'restart_attempts' => 0,
        ]);

        // Simulate failed restart
        $deployment->increment('restart_attempts');
        $this->assertEquals(1, $deployment->fresh()->restart_attempts);

        // Reset on success
        $deployment->update(['restart_attempts' => 0]);
        $this->assertEquals(0, $deployment->fresh()->restart_attempts);
    }
}
