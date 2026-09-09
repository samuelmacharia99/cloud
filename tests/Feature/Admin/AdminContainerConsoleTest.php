<?php

namespace Tests\Feature\Admin;

use App\Enums\ServiceStatus;
use App\Jobs\PullContainerGitRepositoryJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDoctorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminContainerConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_service_show_includes_application_console_tabs(): void
    {
        [$admin, $service] = $this->makeAdminAndNodeService();

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Application console')
            ->assertSee('Redeploy stack')
            ->assertSee('Git')
            ->assertSee('Logs')
            ->assertSee('Files')
            ->assertSee('Terminal')
            ->assertDontSee('cdn.jsdelivr.net/npm/alpinejs');
    }

    public function test_admin_can_fetch_container_logs_without_impersonating(): void
    {
        [$admin, $service] = $this->makeAdminAndNodeService();

        $this->mock(ContainerDeploymentService::class, function ($mock) {
            $mock->shouldReceive('getLogs')
                ->once()
                ->andReturn("app started\nlistening on 3000");
        });

        $this->actingAs($admin)
            ->getJson(route('admin.services.container.logs', $service).'?lines=200')
            ->assertOk()
            ->assertJsonPath('logs', "app started\nlistening on 3000")
            ->assertJsonStructure(['fetched_at']);
    }

    public function test_admin_can_start_a_git_pull(): void
    {
        Queue::fake();

        [$admin, $service] = $this->makeAdminAndNodeService();

        $this->actingAs($admin)
            ->postJson(route('admin.services.container.git-repository.pull', $service), [
                'force_rebuild' => false,
            ])
            ->assertOk()
            ->assertJsonPath('pull.status', 'pending');

        Queue::assertPushed(PullContainerGitRepositoryJob::class);
        $this->assertDatabaseHas('container_git_pulls', [
            'service_id' => $service->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_run_container_doctor(): void
    {
        [$admin, $service] = $this->makeAdminAndNodeService();

        $this->mock(ContainerDoctorService::class, function ($mock) {
            $mock->shouldReceive('diagnose')
                ->once()
                ->andReturn([
                    'healthy' => true,
                    'findings' => [],
                    'lines_scanned' => 20,
                    'scanned_at' => now()->toIso8601String(),
                ]);
        });

        $this->actingAs($admin)
            ->postJson(route('admin.services.container.doctor.diagnose', $service))
            ->assertOk()
            ->assertJsonPath('healthy', true);
    }

    public function test_other_customer_cannot_use_admin_container_routes(): void
    {
        [, $service] = $this->makeAdminAndNodeService();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('admin.services.container.logs', $service))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function makeAdminAndNodeService(): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $node = Node::factory()->containerHost()->create(['is_active' => true]);
        $template = ContainerTemplate::factory()->create(['slug' => 'nodejs', 'name' => 'Node.js']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'language_slug' => 'nodejs',
                'container_template_id' => $template->id,
                'source_repo_url' => 'https://github.com/acme/app.git',
                'source_repo_branch' => 'main',
            ],
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return [$admin, $service->fresh(['product.containerTemplate', 'containerDeployment'])];
    }
}
