<?php

namespace Tests\Unit\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Customer\ProjectConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectConsumptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_sums_six_hour_usage_against_included_plan(): void
    {
        [$project, $deployment] = $this->makeProject();

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 40,
            'memory_used_mb' => 1024,
            'memory_limit_mb' => 4096,
            'memory_percentage' => 25,
            'disk_used_gb' => 8,
            'net_io_rx_bytes' => 0,
            'net_io_tx_bytes' => 0,
            'recorded_at' => now()->subHours(2),
        ]);
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 60,
            'memory_used_mb' => 2048,
            'memory_limit_mb' => 4096,
            'memory_percentage' => 50,
            'disk_used_gb' => 10,
            'net_io_rx_bytes' => 500 * 1024 * 1024,
            'net_io_tx_bytes' => 100 * 1024 * 1024,
            'recorded_at' => now()->subHour(),
        ]);

        $snapshot = app(ProjectConsumptionService::class)->compute($project);

        $this->assertTrue($snapshot['has_samples']);
        $this->assertSame(0.5, $snapshot['cpu_cores']);
        $this->assertSame(1536, $snapshot['memory_mb']);
        $this->assertSame(9.0, $snapshot['disk_gb']);
        $this->assertSame(2.0, $snapshot['included']['cpu']);
        $this->assertSame(25.0, $snapshot['percent']['cpu']);
        $this->assertGreaterThan(0, $snapshot['transfer_bytes']);
    }

    public function test_stale_snapshot_is_refreshed_on_display(): void
    {
        [$project] = $this->makeProject();
        $project->update([
            'consumption_snapshot' => ['cpu_cores' => 9.9, 'has_samples' => true],
            'consumption_snapshot_at' => now()->subHours(7),
        ]);

        $snapshot = app(ProjectConsumptionService::class)->forDisplay($project->fresh());

        $this->assertNotSame(9.9, $snapshot['cpu_cores']);
        $this->assertNotNull($project->fresh()->consumption_snapshot_at);
        $this->assertTrue($project->fresh()->consumption_snapshot_at->greaterThan(now()->subMinute()));
    }

    /**
     * @return array{0: CustomerProject, 1: ContainerDeployment}
     */
    private function makeProject(): array
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create([
            'resource_limits' => ['cpu' => 2, 'memory' => 4096, 'disk' => 40, 'bandwidth_gb' => 100],
        ]);
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addDays(10),
        ]);
        $project->update(['billing_service_id' => $service->id]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
        ]);

        return [$project->fresh(), $deployment];
    }
}
