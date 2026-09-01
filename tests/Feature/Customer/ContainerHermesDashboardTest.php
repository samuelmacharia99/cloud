<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerHermesDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_hermes_overview_shows_dashboard_login_details(): void
    {
        [$customer, $service] = $this->makeHermesService();

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->assertSee('Hermes dashboard')
            ->assertSee('Open dashboard')
            ->assertSee('admin')
            ->assertSee('Reveal')
            ->assertSee('keep-this-dashboard-password');
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function makeHermesService(): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::query()->firstOrCreate(
            ['slug' => 'hermes'],
            [
                'name' => 'Hermes Agent',
                'description' => 'Hermes',
                'category' => 'web',
                'docker_image' => 'nousresearch/hermes-agent:latest',
                'default_port' => 9119,
                'required_ram_mb' => 2048,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 10,
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
            ]
        );
        $template->forceFill([
            'hosting_type' => 'container',
            'is_active' => true,
            'name' => 'Hermes Agent',
        ])->save();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'name' => 'Hermes App',
        ]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'hostname' => 'hermes-node.example.test',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'service_meta' => ['language_slug' => 'hermes'],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'assigned_port' => 31010,
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-hermes',
            'env_values' => [
                'HERMES_DASHBOARD' => '1',
                'HERMES_DASHBOARD_BASIC_AUTH_USERNAME' => 'admin',
                'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD' => 'keep-this-dashboard-password',
            ],
        ]);

        return [$customer, $service->fresh(['product.containerTemplate', 'containerDeployment.node'])];
    }
}
