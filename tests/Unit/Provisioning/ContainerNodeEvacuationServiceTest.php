<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerMigrationService;
use App\Services\Provisioning\ContainerNodeEvacuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ContainerNodeEvacuationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_evacuates_the_heaviest_app_from_a_pressured_host_onto_the_quiet_host(): void
    {
        [$hot, $quiet, $heavy] = $this->pressuredPair();

        $migration = Mockery::mock(ContainerMigrationService::class);
        $migration->shouldReceive('migrate')
            ->once()
            ->with(
                Mockery::on(fn (Service $service) => $service->is($heavy)),
                Mockery::on(fn (Node $node) => $node->is($quiet)),
                'node_capacity'
            );
        $this->app->instance(ContainerMigrationService::class, $migration);

        $result = app(ContainerNodeEvacuationService::class)->evacuatePressuredHosts();

        $this->assertSame([$heavy->id], $result['migrated']);
        $this->assertSame($hot->id, $heavy->node_id);
    }

    public function test_does_not_relocate_when_the_current_host_is_quiet(): void
    {
        $quiet = Node::factory()->containerHost()->create([
            'cpu_cores' => 16,
            'ram_gb' => 32,
            'storage_gb' => 400,
            'cpu_used' => 10,
            'ram_used_gb' => 4,
            'storage_used_gb' => 20,
            'status' => 'online',
            'is_active' => true,
        ]);
        $service = $this->containerOn($quiet, memoryMb: 256);

        $migration = Mockery::mock(ContainerMigrationService::class);
        $migration->shouldReceive('migrate')->never();
        $this->app->instance(ContainerMigrationService::class, $migration);

        $moved = app(ContainerNodeEvacuationService::class)->relocateIfNeeded($service);

        $this->assertFalse($moved);
    }

    public function test_skips_a_recently_migrated_container(): void
    {
        [$hot, $quiet] = $this->pressuredPair(createHeavy: false);
        $service = $this->containerOn($hot, memoryMb: 8192, metricMb: 12000);
        $service->containerDeployment->update([
            'migrated_at' => now()->subHour(),
            'migration_reason' => 'node_capacity',
        ]);

        $migration = Mockery::mock(ContainerMigrationService::class);
        $migration->shouldReceive('migrate')->never();
        $this->app->instance(ContainerMigrationService::class, $migration);

        $result = app(ContainerNodeEvacuationService::class)->evacuatePressuredHosts();

        $this->assertSame([], $result['migrated']);
        $this->assertNotEmpty($result['skipped']);
        $this->assertStringContainsString($hot->hostname, implode(' ', $result['skipped']));
    }

    /**
     * @return array{0: Node, 1: Node, 2?: Service}
     */
    private function pressuredPair(bool $createHeavy = true): array
    {
        $hot = Node::factory()->containerHost()->create([
            'name' => 'hot',
            'cpu_cores' => 16,
            'ram_gb' => 32,
            'storage_gb' => 400,
            'cpu_used' => 20,
            'ram_used_gb' => 28,
            'storage_used_gb' => 40,
            'status' => 'online',
            'is_active' => true,
        ]);
        $quiet = Node::factory()->containerHost()->create([
            'name' => 'quiet',
            'cpu_cores' => 16,
            'ram_gb' => 32,
            'storage_gb' => 400,
            'cpu_used' => 8,
            'ram_used_gb' => 4,
            'storage_used_gb' => 20,
            'status' => 'online',
            'is_active' => true,
        ]);

        if (! $createHeavy) {
            return [$hot, $quiet];
        }

        $light = $this->containerOn($hot, memoryMb: 256, metricMb: 200);
        $heavy = $this->containerOn($hot, memoryMb: 16384, metricMb: 18000);
        $this->assertNotNull($light->id);

        return [$hot, $quiet, $heavy];
    }

    private function containerOn(Node $node, int $memoryMb, int $metricMb = 0): Service
    {
        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 1,
            'required_ram_mb' => 256,
            'required_storage_gb' => 5,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => 1,
                'memory' => $memoryMb,
                'disk' => 10,
            ],
        ]);
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'cpu_limit' => 1,
            'memory_limit_mb' => $memoryMb,
        ]);

        if ($metricMb > 0) {
            ContainerMetric::create([
                'container_deployment_id' => $deployment->id,
                'sample_type' => ContainerMetric::SAMPLE_USAGE,
                'cpu_percentage' => 40,
                'memory_used_mb' => $metricMb,
                'memory_limit_mb' => $memoryMb,
                'memory_percentage' => 90,
                'recorded_at' => now(),
            ]);
        }

        return $service->fresh(['containerDeployment.node', 'product.containerTemplate']);
    }
}
