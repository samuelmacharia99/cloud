<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerNodeAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerNodeAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_live_use_reports_headroom_instead_of_plan_oversell(): void
    {
        $node = $this->makeHost([
            'cpu_cores' => 12,
            'ram_gb' => 62,
            'storage_gb' => 1800,
            'cpu_used' => 5,
            'ram_used_gb' => 10,
            'storage_used_gb' => 144,
            'last_heartbeat_at' => now()->subMinute(),
        ]);
        $this->makeRunningService($node, [
            'cpu' => 16,
            'memory' => 8192,
            'disk' => 1070,
        ]);

        $analytics = app(ContainerNodeAnalyticsService::class)->forNode($node->fresh());

        $this->assertSame(5, $analytics['capacity']['live']['cpu']);
        $this->assertSame(16, $analytics['capacity']['live']['ram']);
        $this->assertSame(8, $analytics['capacity']['live']['storage']);
        $this->assertSame(16, $analytics['live_pressure']);
        $this->assertSame(59, $analytics['capacity']['reserved']['storage']);
        $this->assertGreaterThan(100, $analytics['capacity']['reserved']['cpu']);
        $this->assertSame(16, $analytics['capacity']['pressure_percent']);
        $this->assertTrue(collect($analytics['insights'])->contains(
            fn (array $insight) => str_contains($insight['title'], 'Host has live headroom')
        ));
        $this->assertFalse(collect($analytics['insights'])->contains(
            fn (array $insight) => str_contains($insight['title'], 'Plan CPU is oversold')
                || str_contains($insight['title'], 'Sold disk')
                || str_contains($insight['title'], 'Scale-out pressure')
                || str_contains($insight['title'], 'Live usage is')
        ));
    }

    public function test_failed_runtime_appears_in_attention_and_insights(): void
    {
        $node = $this->makeHost(['last_heartbeat_at' => now()->subMinute()]);
        $service = $this->makeRunningService($node);
        $service->containerDeployment->update(['status' => 'failed']);

        $analytics = app(ContainerNodeAnalyticsService::class)->forNode($node->fresh());

        $this->assertSame(1, $analytics['fleet']['failed']);
        $this->assertSame($service->id, $analytics['attention'][0]['service_id']);
        $this->assertTrue(collect($analytics['insights'])->contains(
            fn (array $insight) => str_contains($insight['title'], 'failed')
        ));
    }

    public function test_top_consumers_rank_by_latest_cpu(): void
    {
        $node = $this->makeHost(['last_heartbeat_at' => now()->subMinute()]);
        $hot = $this->makeRunningService($node, name: 'API gateway');
        $quiet = $this->makeRunningService($node, name: 'Idle worker');

        ContainerMetric::query()->create([
            'container_deployment_id' => $hot->containerDeployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 4.0,
            'memory_used_mb' => 100,
            'memory_limit_mb' => 1024,
            'memory_percentage' => 10,
            'disk_used_gb' => 1.0,
            'recorded_at' => now()->subHours(6),
        ]);
        ContainerMetric::query()->create([
            'container_deployment_id' => $hot->containerDeployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 82.5,
            'memory_used_mb' => 700,
            'memory_limit_mb' => 1024,
            'memory_percentage' => 68,
            'disk_used_gb' => 3.25,
            'recorded_at' => now()->subMinute(),
        ]);
        ContainerMetric::query()->create([
            'container_deployment_id' => $quiet->containerDeployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 2.0,
            'memory_used_mb' => 80,
            'memory_limit_mb' => 1024,
            'memory_percentage' => 8,
            'disk_used_gb' => 0.4,
            'recorded_at' => now()->subHour(),
        ]);

        $analytics = app(ContainerNodeAnalyticsService::class)->forNode($node->fresh());

        $this->assertSame('API gateway', $analytics['top_consumers'][0]['service_name']);
        $this->assertSame(82.5, $analytics['top_consumers'][0]['cpu']);
        $this->assertSame('Idle worker', $analytics['top_consumers'][1]['service_name']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHost(array $overrides = []): Node
    {
        return Node::factory()->containerHost()->create(array_merge([
            'cpu_cores' => 8,
            'ram_gb' => 16,
            'storage_gb' => 200,
            'cpu_used' => 10,
            'ram_used_gb' => 2,
            'storage_used_gb' => 20,
            'status' => 'online',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array{cpu?: float, memory?: int, disk?: float}  $limits
     */
    private function makeRunningService(Node $node, array $limits = [], string $name = 'App'): Service
    {
        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 1,
            'required_ram_mb' => 1024,
            'required_storage_gb' => 10,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => $limits['cpu'] ?? 1,
                'memory' => $limits['memory'] ?? 1024,
                'disk' => $limits['disk'] ?? 10,
            ],
        ]);
        $service = Service::factory()->create([
            'user_id' => User::factory(),
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => $name,
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return $service->fresh(['containerDeployment', 'user', 'product']);
    }
}
