<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CollectContainerMetricsCommand;
use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\CronJob;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\SSH\SSHService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class CollectContainerMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_node_degrades_coverage_without_failing_platform_cron(): void
    {
        Cache::flush();

        $node = Node::factory()->containerHost()->create();
        $deployment = $this->deployment($node);

        $cron = CronJob::create([
            'name' => 'Collect Container Metrics',
            'description' => 'Test',
            'command' => 'cron:collect-container-metrics',
            'schedule' => '*/5 * * * *',
            'enabled' => true,
        ]);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $command = new CollectContainerMetricsCommand(
            $inspector,
            fn (Node $resolved) => throw new \RuntimeException("SSH unavailable for {$resolved->hostname}")
        );
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $this->assertSame(0, $command->handle());
        $this->assertStringContainsString('Temporarily skipped: 1', $output->fetch());
        $this->assertDatabaseHas('cron_job_logs', [
            'cron_job_id' => $cron->id,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('container_deployments', [
            'id' => $deployment->id,
            'status' => 'running',
        ]);
    }

    public function test_disk_probe_failure_retains_last_value_and_keeps_usage_sample(): void
    {
        Cache::flush();
        config(['cron.container_metrics.disk_interval_minutes' => 5]);

        $node = Node::factory()->containerHost()->create();
        $deployment = $this->deployment($node);
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 1,
            'memory_used_mb' => 128,
            'memory_limit_mb' => 512,
            'memory_percentage' => 25,
            'net_io_rx_bytes' => 10,
            'net_io_tx_bytes' => 20,
            'block_io_read_bytes' => 30,
            'block_io_write_bytes' => 40,
            'disk_used_gb' => 4.25,
            'recorded_at' => now()->subMinutes(10),
        ]);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')
            ->once()
            ->with(Mockery::type(SSHService::class), $deployment->container_name, false)
            ->andReturn([
                'missing' => false,
                'running' => true,
                'state' => 'running',
                'oom_killed' => false,
                'exit_code' => 0,
            ]);
        $inspector->shouldReceive('syncDeploymentStatus')->once();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn (string $command) => str_contains($command, 'docker stats')), Mockery::type('int'), false)
            ->andReturn($deployment->container_name."\t2.50%\t256MiB / 512MiB\t1MB / 2MB\t3MB / 4MB");
        $ssh->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn (string $command) => str_contains($command, 'du -sb')), 12, false)
            ->andThrow(new \RuntimeException('du timed out'));
        $ssh->shouldReceive('disconnect')->once();

        $command = new CollectContainerMetricsCommand($inspector, fn () => $ssh);
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $this->assertSame(0, $command->handle());
        $this->assertStringContainsString('Collected metrics for 1 containers', $output->fetch());

        $latest = ContainerMetric::query()
            ->where('container_deployment_id', $deployment->id)
            ->latest('recorded_at')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(4.25, $latest->disk_used_gb);
        $this->assertSame(2.5, $latest->cpu_percentage);
    }

    public function test_one_bad_container_does_not_block_other_samples_or_fail_the_run(): void
    {
        Cache::flush();
        config(['cron.container_metrics.disk_interval_minutes' => 5]);

        $node = Node::factory()->containerHost()->create();
        $bad = $this->deployment($node);
        $good = $this->deployment($node);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        // Two initial inspections + one re-inspect for the container missing from batched stats.
        $inspector->shouldReceive('inspect')->times(3)->andReturn([
            'missing' => false,
            'running' => true,
            'state' => 'running',
            'oom_killed' => false,
            'exit_code' => 0,
        ]);
        $inspector->shouldReceive('syncDeploymentStatus')->twice();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn (string $command) => str_contains($command, 'docker stats')), Mockery::type('int'), false)
            ->andReturn($good->container_name."\t1.25%\t64MiB / 256MiB\t10KB / 20KB\t30KB / 40KB");
        $ssh->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn (string $command) => str_contains($command, 'du -sb')), 12, false)
            ->andReturn('1073741824');
        $ssh->shouldReceive('disconnect')->once();

        $command = new CollectContainerMetricsCommand($inspector, fn () => $ssh);
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $this->assertSame(0, $command->handle());
        $summary = $output->fetch();
        $this->assertStringContainsString('Collected metrics for 1 containers', $summary);
        $this->assertStringContainsString('Temporarily skipped: 1', $summary);
        $this->assertDatabaseCount('container_metrics', 1);
        $this->assertDatabaseHas('container_metrics', [
            'container_deployment_id' => $good->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
        ]);
    }

    public function test_batches_docker_stats_for_all_running_containers_on_a_node(): void
    {
        Cache::flush();
        config(['cron.container_metrics.disk_interval_minutes' => 120]);

        $node = Node::factory()->containerHost()->create();
        $first = $this->deployment($node);
        $second = $this->deployment($node);

        ContainerMetric::create([
            'container_deployment_id' => $first->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 1,
            'memory_used_mb' => 64,
            'memory_limit_mb' => 256,
            'memory_percentage' => 25,
            'net_io_rx_bytes' => 1,
            'net_io_tx_bytes' => 2,
            'block_io_read_bytes' => 3,
            'block_io_write_bytes' => 4,
            'disk_used_gb' => 1.5,
            'recorded_at' => now()->subMinutes(10),
        ]);
        ContainerMetric::create([
            'container_deployment_id' => $second->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 1,
            'memory_used_mb' => 64,
            'memory_limit_mb' => 256,
            'memory_percentage' => 25,
            'net_io_rx_bytes' => 1,
            'net_io_tx_bytes' => 2,
            'block_io_read_bytes' => 3,
            'block_io_write_bytes' => 4,
            'disk_used_gb' => 2.5,
            'recorded_at' => now()->subMinutes(10),
        ]);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->twice()->andReturn([
            'missing' => false,
            'running' => true,
            'state' => 'running',
            'oom_killed' => false,
            'exit_code' => 0,
        ]);
        $inspector->shouldReceive('syncDeploymentStatus')->twice();

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(function (string $command) use ($first, $second) {
                return str_contains($command, 'docker stats')
                    && str_contains($command, $first->container_name)
                    && str_contains($command, $second->container_name)
                    && str_contains($command, '{{.Name}}');
            }), Mockery::type('int'), false)
            ->andReturn(
                $first->container_name."\t1.00%\t64MiB / 256MiB\t1KiB / 2KiB\t3MiB / 4MiB\n"
                .$second->container_name."\t2.00%\t128MiB / 512MiB\t5MiB / 6MiB\t7GiB / 8GiB"
            );
        $ssh->shouldReceive('exec')->never()->withArgs(fn ($command) => is_string($command) && str_contains($command, 'du -sb'));
        $ssh->shouldReceive('disconnect')->once();

        $command = new CollectContainerMetricsCommand($inspector, fn () => $ssh);
        $command->setLaravel($this->app);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $this->assertSame(0, $command->handle());
        $this->assertStringContainsString('Collected metrics for 2 containers', $output->fetch());
        $this->assertDatabaseCount('container_metrics', 4);
    }

    private function deployment(Node $node): ContainerDeployment
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'container',
        ]);

        return ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-'.$service->user_id.'-service-'.$service->id.'-nodejs',
            'status' => 'running',
        ])->fresh(['node', 'service.product.containerTemplate']);
    }
}
