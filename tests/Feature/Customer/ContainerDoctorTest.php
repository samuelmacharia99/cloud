<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDoctorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_logs_tab_includes_doctor_ui(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->mock(ContainerDeploymentService::class, function ($mock) {
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service).'?tab=logs')
            ->assertOk()
            ->assertSee('Container Doctor')
            ->assertSee('Run doctor');
    }

    public function test_diagnose_endpoint_returns_findings(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->mock(ContainerDoctorService::class, function ($mock) {
            $mock->shouldReceive('diagnose')->once()->andReturn([
                'scanned_at' => now()->toIso8601String(),
                'lines_scanned' => 12,
                'stack' => 'laravel',
                'findings' => [[
                    'id' => 'postgres_password_auth_failed',
                    'severity' => 'critical',
                    'title' => 'PostgreSQL password mismatch',
                    'summary' => 'Password mismatch',
                    'evidence' => ['password authentication failed'],
                    'treat_action' => 'sync_database_credentials',
                    'treat_label' => 'Repair DB credentials',
                    'manual_steps' => ['Redeploy with Reset database'],
                ]],
                'healthy' => false,
            ]);
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.doctor.diagnose', $service))
            ->assertOk()
            ->assertJsonPath('stack', 'laravel')
            ->assertJsonPath('findings.0.id', 'postgres_password_auth_failed')
            ->assertJsonPath('healthy', false);
    }

    public function test_treat_endpoint_applies_action(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $serviceId = $service->id;

        $this->mock(ContainerDoctorService::class, function ($mock) use ($serviceId) {
            $mock->shouldReceive('treat')
                ->once()
                ->withArgs(fn ($svc, $action) => $svc->id === $serviceId && $action === 'sync_database_credentials')
                ->andReturn(['success' => true, 'message' => 'Database credentials repaired.']);
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.doctor.treat', $service), [
                'action' => 'sync_database_credentials',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Database credentials repaired.',
            ]);
    }

    public function test_doctor_endpoints_forbid_other_customers(): void
    {
        [, $service] = $this->makeLaravelService();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->postJson(route('customer.services.container.doctor.diagnose', $service))
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson(route('customer.services.container.doctor.treat', $service), [
                'action' => 'restart_application',
            ])
            ->assertForbidden();
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
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-laravel',
        ]);

        return [$customer, $service->fresh(['product.containerTemplate', 'containerDeployment.node'])];
    }
}
