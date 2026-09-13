<?php

namespace Tests\Unit\Services;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\ServiceStatusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A running app with a stopped database is still "active" for billing and
 * enforcement, but the operator must be able to see the database is down.
 */
class ServiceStatusSyncDegradedDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_stopped_database_member_labels_the_service_degraded_without_suspending_it(): void
    {
        Carbon::setTestNow('2026-09-13 10:10:00');
        [$service, $deployment] = $this->deployed();
        $deployment->forceFill([
            'member_states' => [
                'version' => 1,
                'checked_at' => '2026-09-13T10:08:00+00:00',
                'reachable' => true,
                'error' => null,
                'error_at' => null,
                'containers' => [
                    $deployment->container_name => ['state' => 'running', 'status' => 'Up', 'checked_at' => '2026-09-13T10:08:00+00:00', 'service' => null],
                    $deployment->container_name.'-db' => ['state' => 'stopped', 'status' => 'Exited (1)', 'checked_at' => '2026-09-13T10:08:00+00:00', 'service' => 'db'],
                ],
            ],
            'member_states_checked_at' => '2026-09-13 10:08:00',
        ])->save();

        $this->mock(ContainerDeploymentService::class)
            ->shouldReceive('getStatus')->andReturn(['running' => true, 'state' => 'running']);

        $result = app(ServiceStatusSyncService::class)->sync($service->fresh());

        $this->assertSame('active', $result->status);
        $this->assertSame('Container running; database container stopped', $result->label);
        $this->assertSame([$deployment->container_name.'-db'], $result->detail['degraded_members']);
    }

    #[Test]
    public function a_stale_snapshot_does_not_claim_a_database_is_down(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$service, $deployment] = $this->deployed();
        $deployment->forceFill([
            'member_states' => [
                'version' => 1,
                'checked_at' => '2026-09-13T09:00:00+00:00',
                'reachable' => true,
                'error' => null,
                'error_at' => null,
                'containers' => [
                    $deployment->container_name.'-db' => ['state' => 'stopped', 'status' => null, 'checked_at' => '2026-09-13T09:00:00+00:00', 'service' => 'db'],
                ],
            ],
            'member_states_checked_at' => '2026-09-13 09:00:00',
        ])->save();

        $this->mock(ContainerDeploymentService::class)
            ->shouldReceive('getStatus')->andReturn(['running' => true, 'state' => 'running']);

        $result = app(ServiceStatusSyncService::class)->sync($service->fresh());

        $this->assertSame('Container running', $result->label);
        $this->assertArrayNotHasKey('degraded_members', $result->detail);
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function deployed(): array
    {
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create(['product_id' => $product->id, 'status' => 'active', 'provisioning_driver_key' => 'container', 'node_id' => $node->id]);
        $name = 'user-1-service-'.$service->id.'-laravel';
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => $name,
            'status' => 'running',
            'docker_compose_content' => "services:\n  {$name}:\n    image: talksasa/laravel-runtime:8.3\n    container_name: {$name}\n  db:\n    image: mysql:8\n    container_name: {$name}-db\n",
        ]);

        return [$service, $deployment];
    }
}
