<?php

namespace Tests\Feature\Console;

use App\Enums\TelegramMonitorCategory;
use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerIncidentService;
use App\Services\Provisioning\ContainerIntegrityScanner;
use App\Services\Provisioning\WordPressSecurityBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class ScanContainerIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scans_wordpress_stacks_quarantines_certain_hits_and_alerts(): void
    {
        Bus::fake([SendTelegramMonitorAlertJob::class]);
        $node = Node::factory()->containerHost()->create();

        $shop = $this->stack($node, 'wordpress', 'shop-wordpress', 'running');
        $blog = $this->stack($node, 'wordpress', 'blog-wordpress', 'running');
        $blog->update(['service_meta' => ['integrity_scan' => ['hits' => [['path' => 'iisgg8.php', 'reasons' => ['signature'], 'size' => 1, 'mtime' => 1]]]]]);
        $this->stack($node, 'nodejs', 'api-node', 'running');
        $this->stack($node, 'wordpress', 'stopped-wordpress', 'stopped');

        $this->mock(ContainerIncidentService::class, function (MockInterface $incidents) {
            $incidents->shouldReceive('prune')->twice();
            $incidents->shouldReceive('alert')->once()->withArgs(fn ($service, $deployment, $id, $trigger) => $deployment->container_name === 'shop-wordpress' && $id === '20260914-032000-abc123' && $trigger === 'nightly');
        });
        $this->mock(WordPressSecurityBaseline::class, function (MockInterface $baseline) {
            $baseline->shouldReceive('rotateSalts')->once()->andReturn(['success' => true, 'message' => 'rotated']);
        });
        $this->mock(ContainerIntegrityScanner::class, function (MockInterface $scanner) {
            $scanner->makePartial();
            $scanner->shouldReceive('scan')->twice()->andReturnUsing(fn ($ssh, $deployment) => [
                'hits' => $deployment->container_name === 'shop-wordpress'
                    ? [
                        ['path' => 'wp-content/uploads/x.php', 'reasons' => ['php_in_uploads'], 'size' => 5, 'mtime' => 1700000000],
                        ['path' => 'wp-content/qz.php', 'reasons' => ['random_name'], 'size' => 5, 'mtime' => 1700000000],
                    ]
                    : [['path' => 'iisgg8.php', 'reasons' => ['signature', 'unexpected_root_php'], 'size' => 26, 'mtime' => 1700000000]],
                'core' => ['ran' => true, 'version' => '6.6.2', 'modified' => [], 'extra' => [], 'missing' => [], 'error' => null],
                'summary' => ['truncated' => false],
                'scanned_at' => '2026-09-14T03:20:00+00:00',
            ]);
            $scanner->shouldReceive('quarantine')->twice()->andReturnUsing(function ($ssh, $service, $deployment, $hits, $reasons, $trigger) {
                $this->assertSame(ContainerIntegrityScanner::AUTO_QUARANTINE_REASONS, $reasons);
                $this->assertSame('nightly', $trigger);
                if ($deployment->container_name === 'shop-wordpress') {
                    return ['moved' => ['wp-content/uploads/x.php'], 'quarantine_dir' => '/opt/x', 'incident' => '20260914-032000-abc123', 'bytes' => 100, 'skipped' => []];
                }

                return ['moved' => [], 'quarantine_dir' => '', 'incident' => null, 'bytes' => 0, 'skipped' => []];
            });
        });

        $this->artisan('cron:scan-container-integrity')
            ->expectsOutputToContain('Scanned 2 stack(s): 2 with suspicious files, 1 quarantined, 1 alerts, 0 scan failures')
            ->assertExitCode(0);

        $meta = $shop->fresh()->service_meta['integrity_scan'];
        $this->assertSame(['wp-content/qz.php'], array_column($meta['hits'], 'path'), 'the quarantined path is no longer a stored hit');
        $this->assertSame(1, $meta['quarantined_count']);
        $this->assertSame('6.6.2', $meta['core_version']);

        // The blog's hit was already known: no new alert for it.
        Bus::assertNotDispatched(SendTelegramMonitorAlertJob::class);
    }

    public function test_report_only_mode_alerts_on_new_hits_without_removing_anything(): void
    {
        Bus::fake([SendTelegramMonitorAlertJob::class]);
        $node = Node::factory()->containerHost()->create();
        $this->stack($node, 'wordpress', 'one-wordpress', 'running');

        $this->mock(ContainerIncidentService::class, fn (MockInterface $incidents) => $incidents->shouldReceive('prune')->once());
        $this->mock(ContainerIntegrityScanner::class, function (MockInterface $scanner) {
            $scanner->makePartial();
            $scanner->shouldReceive('scan')->once()->andReturn([
                'hits' => [['path' => 'wp-content/uploads/x.php', 'reasons' => ['php_in_uploads'], 'size' => 5, 'mtime' => 1]],
                'core' => ['ran' => false, 'version' => null, 'modified' => [], 'extra' => [], 'missing' => [], 'error' => 'offline'],
                'summary' => ['truncated' => false],
                'scanned_at' => '2026-09-14T03:20:00+00:00',
            ]);
            $scanner->shouldNotReceive('quarantine');
        });

        $this->artisan('cron:scan-container-integrity', ['--no-quarantine' => true])
            ->expectsOutputToContain('Scanned 1 stack(s): 1 with suspicious files, 0 quarantined, 1 alerts, 0 scan failures')
            ->assertExitCode(0);

        Bus::assertDispatched(SendTelegramMonitorAlertJob::class, fn (SendTelegramMonitorAlertJob $job) => $job->category === 'security' && str_contains($job->title, 'one-wordpress') && $job->fields['new files'] === 1);
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
            ->expectsOutputToContain('Scanned 0 stack(s): 0 with suspicious files, 0 quarantined, 0 alerts, 1 scan failures')
            ->assertExitCode(0);
        Bus::assertNotDispatched(SendTelegramMonitorAlertJob::class);
    }

    public function test_security_alerts_are_deliverable(): void
    {
        $this->assertSame('security', TelegramMonitorCategory::Security->value);
        $this->assertSame('telegram_monitor_security', TelegramMonitorCategory::Security->settingKey());
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
