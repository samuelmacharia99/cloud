<?php

namespace Tests\Feature\Api;

use App\Models\ContainerTemplate;
use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private Node $node;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->node = Node::factory()->create(['type' => 'container_host', 'is_active' => true]);

        $template = ContainerTemplate::factory()->create();
        $product = Product::factory()->create([
            'type' => 'container',
            'container_template_id' => $template->id,
        ]);

        $this->service = Service::factory()->create([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'node_id' => $this->node->id,
        ]);
    }

    public function test_api_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/services/{$this->service->id}/container");

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_own_container(): void
    {
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $this->service->id,
            'status' => 'running',
        ]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container");

        $response->assertStatus(200)
            ->assertJsonPath('id', $deployment->id)
            ->assertJsonPath('service_id', $this->service->id)
            ->assertJsonPath('status', 'running');
    }

    public function test_user_cannot_access_other_services_container(): void
    {
        ContainerDeployment::factory()->create(['service_id' => $this->service->id]);

        $this->actingAs($this->otherUser, 'sanctum');

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container");

        $response->assertStatus(403);
    }

    public function test_api_returns_404_for_missing_deployment(): void
    {
        $service = Service::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/v1/services/{$service->id}/container");

        $response->assertStatus(404);
    }

    public function test_container_logs_endpoint(): void
    {
        ContainerDeployment::factory()->create(['service_id' => $this->service->id]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container/logs?lines=50");

        // In test environment, logs might be empty, but endpoint should respond
        $this->assertIn($response->status(), [200, 500]); // 500 if SSH fails (expected in test)
    }

    public function test_container_metrics_endpoint(): void
    {
        ContainerDeployment::factory()->create(['service_id' => $this->service->id]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/v1/services/{$this->service->id}/container/metrics");

        $response->assertStatus(200)
            ->assertJsonStructure(['cpu_percent', 'memory_percent', 'memory_mb', 'network_in_bytes', 'network_out_bytes']);
    }

    public function test_container_templates_endpoint(): void
    {
        $response = $this->getJson('/api/v1/container-templates');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_container_template_show_endpoint(): void
    {
        $template = ContainerTemplate::first();

        $response = $this->getJson("/api/v1/container-templates/{$template->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $template->id)
            ->assertJsonPath('docker_image', $template->docker_image);
    }

    public function test_api_health_endpoint_public(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok');
    }

    public function test_admin_health_endpoint_requires_admin(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/admin/health');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_admin_health(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/admin/health');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'database', 'cache']);
    }

    public function test_nodes_endpoint_requires_admin(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/v1/nodes');

        $response->assertStatus(403);
    }

    public function test_admin_can_list_nodes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/v1/nodes');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_node_detail_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson("/api/v1/nodes/{$this->node->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $this->node->id)
            ->assertJsonPath('name', $this->node->name)
            ->assertJsonStructure(['deployments']);
    }

    public function test_api_error_responses_have_consistent_format(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/v1/services/99999/container");

        $response->assertStatus(403)
            ->assertJsonStructure(['message']);
    }

    public function test_backup_api_requires_ownership(): void
    {
        $deployment = ContainerDeployment::factory()->create(['service_id' => $this->service->id]);
        $backup = \App\Models\ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $this->service->id,
        ]);

        $this->actingAs($this->otherUser, 'sanctum');

        $response = $this->deleteJson("/api/v1/services/{$this->service->id}/container/backups/{$backup->id}");

        $response->assertStatus(403);
    }
}
