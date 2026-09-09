<?php

namespace Tests\Feature\Admin;

use App\Jobs\MigrateContainerServiceJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerMigrationProgress;
use App\Services\Provisioning\ContainerMigrationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContainerMigrationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_page_describes_verified_named_volume_cutover(): void
    {
        [$admin, $service, , $target] = $this->models();
        $this->mock(ContainerMigrationService::class, function ($mock) use ($target) {
            $mock->shouldReceive('getAvailableTargetNodes')->once()->andReturn(new Collection([$target]));
        });

        $this->actingAs($admin)
            ->get(route('admin.services.container.migrate', $service))
            ->assertOk()
            ->assertSee('every Docker named volume')
            ->assertSee('SHA-256 checksums')
            ->assertDontSee('Named database volumes stay on the source');
    }

    public function test_migration_requires_explicit_downtime_confirmation(): void
    {
        [$admin, $service, , $target] = $this->models();
        $this->mock(ContainerMigrationService::class, function ($mock) {
            $mock->shouldNotReceive('migrate');
        });

        $this->actingAs($admin)
            ->post(route('admin.services.container.migrate.confirm', $service), [
                'target_node_id' => $target->id,
                'reason' => 'planned_maintenance',
            ])
            ->assertSessionHasErrors('confirm_downtime');
    }

    public function test_migration_rejects_the_current_node(): void
    {
        [$admin, $service, $source] = $this->models();
        $this->mock(ContainerMigrationService::class, function ($mock) {
            $mock->shouldNotReceive('migrate');
        });

        $this->actingAs($admin)
            ->post(route('admin.services.container.migrate.confirm', $service), [
                'target_node_id' => $source->id,
                'reason' => 'manual',
                'confirm_downtime' => '1',
            ])
            ->assertSessionHasErrors('target_node_id');
    }

    public function test_migration_is_queued_to_a_worker_and_opens_the_console(): void
    {
        Queue::fake();
        [$admin, $service, $source, $target] = $this->models();
        $this->mock(ContainerMigrationService::class, function ($mock) {
            // A multi-gigabyte move must never run inside the request.
            $mock->shouldNotReceive('migrate');
        });

        $this->actingAs($admin)
            ->post(route('admin.services.container.migrate.confirm', $service), [
                'target_node_id' => $target->id,
                'reason' => 'rebalancing',
                'confirm_downtime' => '1',
            ])
            ->assertRedirect(route('admin.services.container.migrate', $service))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'queued'));

        Queue::assertPushed(
            MigrateContainerServiceJob::class,
            fn (MigrateContainerServiceJob $job): bool => $job->serviceId === $service->id
                && $job->targetNodeId === $target->id
                && $job->reason === 'rebalancing',
        );

        $view = app(ContainerMigrationProgress::class)->operatorView($service->fresh());
        $this->assertSame('queued', $view['status']);
        $this->assertTrue($view['is_active']);
        $this->assertSame($source->hostname, $view['source_hostname']);
        $this->assertSame($target->hostname, $view['target_hostname']);
    }

    public function test_a_second_migration_cannot_be_queued_while_one_is_running(): void
    {
        Queue::fake();
        [$admin, $service, $source, $target] = $this->models();
        app(ContainerMigrationProgress::class)->queue($service, $source, $target, 'manual');

        $this->actingAs($admin)
            ->post(route('admin.services.container.migrate.confirm', $service), [
                'target_node_id' => $target->id,
                'reason' => 'manual',
                'confirm_downtime' => '1',
            ])
            ->assertSessionHasErrors('error');

        Queue::assertNothingPushed();
    }

    public function test_console_endpoint_streams_progress_to_the_admin(): void
    {
        [$admin, $service, $source, $target] = $this->models();
        $progress = app(ContainerMigrationProgress::class);
        $progress->queue($service, $source, $target, 'upgrade');
        $progress->phase($service, 'transfer', 'Copying 1.0 GB to '.$target->hostname);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.services.container.migrate.progress', $service))
            ->assertOk();

        $this->assertSame('transfer', $response->json('phase'));
        $this->assertTrue($response->json('is_active'));
        $this->assertGreaterThan(0, $response->json('percent'));
        $this->assertStringContainsString('Copying 1.0 GB', $response->json('log'));

        $steps = collect($response->json('steps'));
        $this->assertSame('completed', $steps->firstWhere('key', 'preflight')['status']);
        $this->assertSame('running', $steps->firstWhere('key', 'transfer')['status']);
        $this->assertSame('pending', $steps->firstWhere('key', 'cleanup')['status']);
    }

    public function test_console_endpoint_is_closed_to_non_admins(): void
    {
        [, $service] = $this->models();
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('admin.services.container.migrate.progress', $service))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Service, 2: Node, 3: Node}
     */
    private function models(): array
    {
        $admin = User::factory()->admin()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $source = Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
        ]);
        $target = Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $source->id,
            'status' => 'active',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $source->id,
            'container_name' => 'migration-request-'.$service->id,
            'status' => 'running',
        ]);

        return [$admin, $service->fresh(['containerDeployment', 'product']), $source, $target];
    }
}
