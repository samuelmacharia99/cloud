<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeployResult;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerContainerRedeployStackTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_page_shows_redeploy_stack_selectors(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->mock(ContainerDeploymentService::class, function ($mock) {
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->assertSee('Redeploy stack')
            ->assertSee('Frontend')
            ->assertSee('Database');
    }

    public function test_redeploy_persists_frontend_and_database_selection(): void
    {
        [$customer, $service] = $this->makeLaravelService();
        $database = $this->makeDatabase('postgresql');

        $this->mock(ContainerDeploymentService::class, function ($mock) {
            $mock->shouldReceive('deploy')
                ->once()
                ->andReturn(new ContainerDeployResult(databaseReset: true));
        });

        $this->actingAs($customer)
            ->post(route('customer.services.container.redeploy', $service), [
                'frontend' => 'vite-spa',
                'database_id' => $database->id,
                'reset_database' => '1',
            ])
            ->assertRedirect();

        $service->refresh();
        $this->assertSame('vite-spa', $service->service_meta['frontend'] ?? null);
        $this->assertSame($database->id, (int) ($service->service_meta['database_id'] ?? 0));
        $this->assertSame($database->name, $service->service_meta['database_template_name'] ?? null);
    }

    public function test_redeploy_rejects_invalid_stack_combination(): void
    {
        [$customer, $service] = $this->makeLaravelService();
        $mongo = $this->makeDatabase('mongodb');

        // Force a php template which only allows mysql/mariadb in matrix when slug is php —
        // Laravel allows mongodb, so use wordpress for invalid combo instead.
        $wp = ContainerTemplate::query()->firstOrCreate(
            ['slug' => 'wordpress'],
            [
                'name' => 'WordPress',
                'description' => 'WordPress',
                'category' => 'web',
                'docker_image' => 'wordpress:latest',
                'default_port' => 80,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 2,
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
            ]
        );
        $wp->forceFill(['hosting_type' => 'container', 'is_active' => true])->save();
        $service->product->update(['container_template_id' => $wp->id]);
        $service->unsetRelation('product');

        $this->actingAs($customer)
            ->from(route('customer.services.container.show', $service))
            ->post(route('customer.services.container.redeploy', $service), [
                'frontend' => 'none',
                'database_id' => $mongo->id,
            ])
            ->assertRedirect(route('customer.services.container.show', $service))
            ->assertSessionHasErrors('error');
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function makeLaravelService(): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::query()->firstOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel app',
                'category' => 'web',
                'docker_image' => 'php:8.3',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 2,
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
            ]
        );
        $template->forceFill([
            'hosting_type' => 'container',
            'is_active' => true,
            'name' => 'Laravel',
        ])->save();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'name' => 'Laravel App',
        ]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'service_meta' => [
                'frontend' => 'none',
                'framework' => null,
            ],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-laravel',
        ]);

        return [$customer, $service->fresh(['product.containerTemplate', 'containerDeployment.node'])];
    }

    private function makeDatabase(string $type): DatabaseTemplate
    {
        return DatabaseTemplate::query()->create([
            'name' => ucfirst($type),
            'slug' => $type.'-test-'.uniqid(),
            'description' => $type,
            'type' => $type,
            'docker_image' => $type.':latest',
            'default_port' => 5432,
            'required_ram_mb' => 256,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 1,
        ]);
    }
}
