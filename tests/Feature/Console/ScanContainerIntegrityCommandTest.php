<?php

namespace Tests\Feature\Console;

use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerIntegrityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanContainerIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scans_wordpress_stacks_records_results_and_alerts_on_new_hits(): void
    {
        Bus::fake([SendTelegramMonitorAlertJob::class]);
        $node = Node::factory()->containerHost()->create();

        $wordpress = $this->stack($node, 'wordpress', 'shop-wordpress', 'running');
        $alreadyKnown = $this->stack($node, 'wordpress', 'blog-wordpress', 'running');
        $alreadyKnown->update(['service_meta' => ['integrity_scan' => ['hits' => [['path' => 'iisgg8.php', 'reasons' => ['signature'], 'size' => 1, 'mtime' => 1]]]]]);
        $this->stack($node, 'nodejs', 'api-node', 'running');
        $this->stack($node, 'wordpress', 'stopped-wordpress', 'stopped');

        $this->mock(ContainerIntegrityScanner::class, function (MockInterface $scanner) {
            $scanner->makePartial();
            $scanner->shouldReceive('scan')->twice()->andReturn([
                'hits' => [['path' => 'iisgg8.php', 'reasons' => ['signature', 'unexpected_root_php'], 'size' => 26, 'mtime' => 1700000000]],
                'core' => ['modified' => [], 'extra' => [], 'ran' => true],
                'scanned_at' => '2026-09-14T03:20:00+00:00',
            ]);
        });

        $this->artisan('cron:scan-container-integrity')
            ->expectsOutputToContain('Scanned 2 stack(s): 2 with suspicious files, 1 new alerts, 0 scan failures')
            ->assertExitCode(0);

        $meta = $wordpress->fresh()->service_meta['integrity_scan'];
        $this->assertSame(1, $meta['suspicious_count']);
        $this->assertSame('iisgg8.php', $meta['hits'][0]['path']);
        $this->assertTrue($meta['core_checked']);

        Bus::assertDispatched(SendTelegramMonitorAlertJob::class, function (SendTelegramMonitorAlertJob $job) {
            return $job->category === 'security'
                && str_contains($job->title, 'shop-wordpress')
                && $job->fields['new files'] === 1;
        });
        Bus::assertDispatchedTimes(SendTelegramMonitorAlertJob::class, 1);
    }

    public function test_a_failing_node_is_counted_and_does_not_stop_the_pass(): void
    {
        Bus::fake([SendTelegramMonitorAlertJob::class]);
        $node = Node::factory()->containerHost()->create();
        $this->stack($node, 'wordpress', 'one-wordpress', 'running');

        $this->mock(ContainerIntegrityScanner::class, function (MockInterface $scanner) {
            $scanner->makePartial();
            $scanner->shouldReceive('scan')->once()->andThrow(new \RuntimeException('SSH connection failed'));
        });

        $this->artisan('cron:scan-container-integrity')
            ->expectsOutputToContain('Scanned 0 stack(s): 0 with suspicious files, 0 new alerts, 1 scan failures')
            ->assertExitCode(0);
        Bus::assertNotDispatched(SendTelegramMonitorAlertJob::class);
    }

    private function stack(Node $node, string $slug, string $containerName, string $status): Service
    {
        $template = ContainerTemplate::query()->firstOrCreate(['slug' => $slug], ContainerTemplate::factory()->make(['slug' => $slug])->getAttributes());
        $product = Product::factory()->create(['type' => 'container_hosting', 'container_template_id' => $template->id]);
        $service = Service::factory()->create(['node_id' => $node->id, 'product_id' => $product->id, 'provisioning_driver_key' => 'container', 'name' => $containerName]);
        ContainerDeployment::factory()->create(['service_id' => $service->id, 'node_id' => $node->id, 'container_name' => $containerName, 'status' => $status]);

        return $service;
    }
}
