<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeploymentEvent;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaConvertProgress;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DaConvertProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_percent_follows_the_weighted_phase_table(): void
    {
        $progress = app(DaConvertProgress::class);

        $this->assertSame(14, $progress->percentFor([
            'status' => 'running', 'mode' => DaConvertProgress::MODE_PRIMARY, 'phase' => 'export', 'phase_fraction' => 0.5,
        ]));
        $this->assertSame(24, $progress->percentFor([
            'status' => 'running', 'mode' => DaConvertProgress::MODE_PRIMARY, 'phase' => 'deploy', 'phase_fraction' => 0.0,
        ]));
        $this->assertSame(74, $progress->percentFor([
            'status' => 'running', 'mode' => DaConvertProgress::MODE_PRIMARY, 'phase' => 'bind', 'phase_fraction' => 0.5,
        ]));
        $this->assertSame(24, $progress->percentFor([
            'status' => 'running', 'mode' => DaConvertProgress::MODE_SITE, 'phase' => 'export', 'phase_fraction' => 0.5,
        ]));
        $this->assertSame(100, $progress->percentFor(['status' => 'completed', 'phase' => 'export']));
        $this->assertSame(1, $progress->percentFor(['status' => 'queued']));
        $this->assertSame(0, $progress->percentFor([]));
    }

    public function test_mail_phase_blends_the_mail_pull_percent_into_its_span(): void
    {
        $progress = app(DaConvertProgress::class);
        $convert = ['status' => 'running', 'mode' => DaConvertProgress::MODE_PRIMARY, 'phase' => 'mail', 'phase_fraction' => 0.0];

        $this->assertSame(78, $progress->percentFor($convert, ['percent' => 0]));
        $this->assertSame(85, $progress->percentFor($convert, ['percent' => 50]));
        $this->assertSame(92, $progress->percentFor($convert, ['percent' => 100]));
    }

    public function test_legacy_meta_without_phases_keeps_the_old_step_heuristic(): void
    {
        $progress = app(DaConvertProgress::class);

        $this->assertSame(31, $progress->percentFor(['status' => 'running', 'steps' => ['a', 'b', 'c']]));
        $this->assertSame(92, $progress->percentFor(['status' => 'running', 'steps' => array_fill(0, 20, 'x')]));
        $this->assertSame(31, $progress->percentFor(['status' => 'failed', 'steps' => ['a', 'b', 'c']]));
    }

    public function test_steps_persist_with_phase_and_heartbeat_and_complete_marks_one_hundred(): void
    {
        $service = Service::factory()->create();
        $progress = app(DaConvertProgress::class);

        $progress->start($service, ['previous' => ['product_id' => 5]]);
        $progress->step($service, 'Preflight OK', 'preflight', 1.0);
        $progress->step($service, 'Exporting', 'export');
        $progress->phase($service, 'export', 'Downloading files archive 1.0 MB / 2.0 MB', 0.5, force: true);

        $meta = $service->fresh()->service_meta['da_convert'];
        $this->assertSame('running', $meta['status']);
        $this->assertSame(['Preflight OK', 'Exporting'], $meta['steps']);
        $this->assertSame('export', $meta['phase']);
        $this->assertSame(14, $meta['percent']);
        $this->assertSame(['product_id' => 5], $meta['previous']);
        $this->assertNotEmpty($meta['heartbeat_at']);
        $this->assertSame('Downloading files archive 1.0 MB / 2.0 MB', $meta['phase_detail']);

        $progress->complete($service, ['renewal_unit_price' => 12.5]);
        $meta = $service->fresh()->service_meta['da_convert'];
        $this->assertSame('completed', $meta['status']);
        $this->assertSame(100, $meta['percent']);
        $this->assertSame(12.5, $meta['renewal_unit_price']);
        $this->assertSame(['product_id' => 5], $meta['previous']);
    }

    public function test_merge_reads_the_row_fresh_so_other_writers_are_not_clobbered(): void
    {
        $service = Service::factory()->create(['service_meta' => []]);
        $progress = app(DaConvertProgress::class);
        $progress->start($service);

        // Another process (mail pull) writes to service_meta behind this instance's back.
        Service::query()->whereKey($service->id)->update([
            'service_meta' => json_encode(array_merge($service->fresh()->service_meta, ['mail_pull' => ['status' => 'running']])),
        ]);

        $progress->step($service, 'Next step');

        $fresh = $service->fresh()->service_meta;
        $this->assertSame(['status' => 'running'], $fresh['mail_pull']);
        $this->assertSame(['Next step'], $fresh['da_convert']['steps']);
    }

    public function test_siblings_are_read_from_the_convert_project_even_after_rollback_unlinked_the_primary(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $primary = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => null,
            'service_meta' => ['domain' => 'example.com', 'da_convert' => ['status' => 'failed', 'error' => 'boom']],
        ]);
        $project = CustomerProject::factory()->create([
            'user_id' => $user->id,
            'billing_service_id' => $primary->id,
            'recipe_key' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
        ]);
        $failedSite = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'failed',
            'service_meta' => [
                'domain' => 'app.example.com',
                'project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
                'project_role' => 'site',
                'da_convert' => ['status' => 'failed', 'error' => 'tar exploded', 'mode' => DaConvertProgress::MODE_SITE],
            ],
        ]);
        $runningSite = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'provisioning',
            'service_meta' => [
                'domain' => 'blog.example.com',
                'project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
                'project_role' => 'site',
                'da_convert' => [
                    'status' => 'running', 'mode' => DaConvertProgress::MODE_SITE, 'phase' => 'deploy',
                    'heartbeat_at' => now()->toIso8601String(), 'steps' => ['Provisioning container'],
                ],
            ],
        ]);
        // A mail service on the same project is not a site.
        Service::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'service_meta' => ['project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY, 'project_role' => 'mail'],
        ]);

        $rows = app(DaConvertProgress::class)->siblingsFor($primary);

        $this->assertCount(2, $rows);
        $this->assertSame([$failedSite->id, $runningSite->id], array_column($rows, 'service_id'));
        $this->assertSame('app.example.com', $rows[0]['domain']);
        $this->assertSame('failed', $rows[0]['convert_status']);
        $this->assertSame('tar exploded', $rows[0]['label']);
        $this->assertTrue($rows[0]['can_retry']);
        $this->assertFalse($rows[0]['is_active']);
        $this->assertStringContainsString('/retry-convert', $rows[0]['retry_url']);
        $this->assertTrue($rows[1]['is_active']);
        $this->assertFalse($rows[1]['can_retry']);
        $this->assertSame(40, $rows[1]['percent']);

        $view = app(DaConvertProgress::class)->operatorConvertView($primary);
        $this->assertSame(2, $view['siblings_total']);
        $this->assertSame(1, $view['siblings_failed']);
        $this->assertTrue($view['siblings_active']);
        $this->assertFalse($view['is_site']);

        $siteView = app(DaConvertProgress::class)->operatorConvertView($failedSite);
        $this->assertTrue($siteView['is_site']);
        $this->assertSame($primary->id, $siteView['primary']['service_id']);
        $this->assertSame('example.com', $siteView['primary']['domain']);
        $this->assertTrue($siteView['can_retry_convert']);
        $this->assertSame('Retry site', $siteView['retry_convert_label']);
    }

    public function test_deploy_lines_only_include_events_since_this_convert_started(): void
    {
        $service = Service::factory()->create();
        $startedAt = now()->subMinutes(5);
        ContainerDeploymentEvent::create([
            'service_id' => $service->id,
            'event' => 'deploy_succeeded',
            'payload' => [],
            'recorded_at' => $startedAt->copy()->subHour(),
        ]);
        ContainerDeploymentEvent::create([
            'service_id' => $service->id,
            'event' => 'node_selected',
            'payload' => ['node_hostname' => 'c1.example.net'],
            'recorded_at' => $startedAt->copy()->addMinute(),
        ]);

        $lines = app(DaConvertProgress::class)->deployLinesFor($service, ['started_at' => $startedAt->toIso8601String()]);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Host selected: c1.example.net', $lines[0]);
    }

    public function test_mail_pull_writes_and_deploy_events_count_as_convert_activity(): void
    {
        $progress = app(DaConvertProgress::class);
        $stale = now()->subHour()->toIso8601String();
        $convert = ['status' => 'running', 'started_at' => $stale, 'heartbeat_at' => $stale];

        $quiet = Service::factory()->create();
        $this->assertTrue($progress->looksStuck($convert, $quiet));

        $mailing = Service::factory()->create(['service_meta' => ['mail_pull' => ['status' => 'running', 'updated_at' => now()->subMinute()->toIso8601String()]]]);
        $this->assertFalse($progress->looksStuck($convert, $mailing));

        $deploying = Service::factory()->create();
        ContainerDeploymentEvent::create(['service_id' => $deploying->id, 'event' => 'compose_up_started', 'payload' => [], 'recorded_at' => now()->subMinutes(2)]);
        $this->assertFalse($progress->looksStuck($convert, $deploying));

        // An event from before this convert started is not activity.
        $old = Service::factory()->create();
        ContainerDeploymentEvent::create(['service_id' => $old->id, 'event' => 'deploy_succeeded', 'payload' => [], 'recorded_at' => now()->subHours(2)]);
        $this->assertTrue($progress->looksStuck($convert, $old));
    }

    public function test_primary_retry_is_offered_from_terminal_states_and_stuck_runs_only(): void
    {
        $progress = app(DaConvertProgress::class);
        $service = Service::factory()->create();
        $base = ['previous' => ['product_id' => 3], 'options' => ['product_id' => 9]];

        $this->assertTrue($progress->canRetryPrimary($service, $base + ['status' => 'failed']));
        $this->assertTrue($progress->canRetryPrimary($service, $base + ['status' => 'completed']));
        $this->assertTrue($progress->canRetryPrimary($service, $base + ['status' => 'reverted']));
        $this->assertFalse($progress->canRetryPrimary($service, $base + ['status' => 'running', 'heartbeat_at' => now()->toIso8601String()]));
        $this->assertTrue($progress->canRetryPrimary($service, $base + ['status' => 'running', 'heartbeat_at' => now()->subHour()->toIso8601String()]));
        $this->assertFalse($progress->canRetryPrimary($service, ['status' => 'failed']));
        $this->assertFalse($progress->canRetryPrimary($service, ['status' => 'failed', 'previous' => ['product_id' => 3]]));

        $site = Service::factory()->create(['service_meta' => [
            'project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
            'project_role' => 'site',
        ]]);
        $this->assertFalse($progress->canRetryPrimary($site, $base + ['status' => 'failed']));
    }
}
