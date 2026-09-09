<?php

namespace Tests\Unit\Provisioning;

use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerMigrationProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerMigrationProgressTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function queueing_a_move_names_both_hosts_and_marks_the_console_busy(): void
    {
        [$progress, $service, $source, $target] = $this->progress();

        $progress->queue($service, $source, $target, 'planned_maintenance');
        $view = $progress->operatorView($service);

        $this->assertSame('queued', $view['status']);
        $this->assertTrue($view['is_active']);
        $this->assertFalse($view['can_start']);
        $this->assertSame($source->hostname, $view['source_hostname']);
        $this->assertSame($target->hostname, $view['target_hostname']);
        $this->assertSame('planned_maintenance', $view['reason']);
        $this->assertStringContainsString($target->hostname, $view['log']);
    }

    #[Test]
    public function percent_climbs_as_the_move_advances_and_lands_on_one_hundred(): void
    {
        [$progress, $service, $source, $target] = $this->progress();
        $progress->queue($service, $source, $target, 'manual');

        $seen = [$progress->operatorView($service)['percent']];
        foreach (['preflight', 'snapshot', 'transfer', 'restore', 'start', 'verify', 'cutover', 'domains', 'cleanup'] as $phase) {
            $progress->phase($service, $phase);
            $seen[] = $progress->operatorView($service)['percent'];
        }

        foreach (array_slice($seen, 1) as $index => $percent) {
            $this->assertGreaterThanOrEqual($seen[$index], $percent, 'Percent must never move backwards.');
        }
        $this->assertGreaterThan($seen[0], end($seen), 'Percent must advance as phases complete.');
        $this->assertLessThan(100, max($seen), 'Only a finished migration may report 100%.');

        $progress->complete($service, 'Moved to '.$target->hostname);
        $this->assertSame(100, $progress->operatorView($service)['percent']);
        $this->assertTrue($progress->operatorView($service)['is_done']);
        $this->assertFalse($progress->operatorView($service)['is_active']);
    }

    #[Test]
    public function an_automatic_evacuation_opens_the_console_without_the_admin_form(): void
    {
        [$progress, $service, $source, $target] = $this->progress();

        // Cron-driven evacuations skip queue() and call the migration service directly.
        $progress->begin($service, $source, $target, 'node_capacity');
        $view = $progress->operatorView($service);

        $this->assertSame('running', $view['status']);
        $this->assertSame($source->hostname, $view['source_hostname']);
        $this->assertSame($target->hostname, $view['target_hostname']);
        $this->assertSame('node_capacity', $view['reason']);
    }

    #[Test]
    public function beginning_a_retry_clears_the_previous_failure(): void
    {
        [$progress, $service, $source, $target] = $this->progress();
        $progress->queue($service, $source, $target, 'manual');
        $progress->fail($service, 'Target host has insufficient free disk space.', rolledBack: true);

        $progress->begin($service, $source, $target, 'manual');
        $view = $progress->operatorView($service);

        $this->assertFalse($view['is_failed']);
        $this->assertNull($view['error']);
        $this->assertFalse($view['rolled_back']);
        $this->assertStringNotContainsString('insufficient free disk', $view['log']);
    }

    #[Test]
    public function transfer_bytes_drive_progress_inside_a_single_phase(): void
    {
        [$progress, $service, $source, $target] = $this->progress();
        $progress->queue($service, $source, $target, 'manual');

        $progress->phase($service, 'transfer', 'Copying', 0, 1000);
        $start = $progress->operatorView($service)['percent'];
        $progress->phase($service, 'transfer', 'Copying', 500, 1000);
        $half = $progress->operatorView($service)['percent'];

        $this->assertGreaterThan($start, $half);
        $this->assertSame(1000, $progress->operatorView($service)['bytes_total']);
    }

    #[Test]
    public function steps_mark_the_failing_phase_and_record_that_the_app_was_restored(): void
    {
        [$progress, $service, $source, $target] = $this->progress();
        $progress->queue($service, $source, $target, 'manual');
        $progress->phase($service, 'restore');

        $progress->fail($service, 'Volume archive verification failed for service-db.', rolledBack: true);
        $view = $progress->operatorView($service);

        $this->assertTrue($view['is_failed']);
        $this->assertTrue($view['rolled_back']);
        $this->assertTrue($view['can_start'], 'A failed run must not block the next attempt.');
        $this->assertSame('restore', $view['failed_phase']);
        $this->assertStringContainsString('FAILED: Volume archive verification failed', $view['log']);
        $this->assertStringContainsString('No data was released', $view['log']);

        $steps = collect($view['steps']);
        $this->assertSame('completed', $steps->firstWhere('key', 'transfer')['status']);
        $this->assertSame('failed', $steps->firstWhere('key', 'restore')['status']);
        $this->assertSame('pending', $steps->firstWhere('key', 'start')['status']);
    }

    #[Test]
    public function the_log_survives_a_cache_flush_by_falling_back_to_the_service_record(): void
    {
        [$progress, $service, $source, $target] = $this->progress();
        $progress->queue($service, $source, $target, 'upgrade');
        $progress->phase($service, 'snapshot', 'Snapshotting files and volumes');

        Cache::flush();

        $view = $progress->operatorView($service->fresh());
        $this->assertSame('snapshot', $view['phase']);
        $this->assertStringContainsString('Snapshotting files and volumes', $view['log']);
    }

    #[Test]
    public function a_dead_worker_is_closed_out_but_a_finished_run_is_left_alone(): void
    {
        [$progress, $service, $source, $target] = $this->progress();
        $progress->queue($service, $source, $target, 'manual');
        $progress->phase($service, 'transfer');

        $progress->failIfActive($service, 'The migration worker stopped unexpectedly.');
        $this->assertTrue($progress->operatorView($service)['is_failed']);

        $progress->complete($service, 'Done');
        $progress->failIfActive($service, 'should not overwrite');
        $this->assertSame('Done', $progress->operatorView($service)['label']);
    }

    /**
     * @return array{0: ContainerMigrationProgress, 1: Service, 2: Node, 3: Node}
     */
    private function progress(): array
    {
        $source = Node::factory()->create(['type' => 'container_host', 'hostname' => 'node-a.talksasa.test']);
        $target = Node::factory()->create(['type' => 'container_host', 'hostname' => 'node-b.talksasa.test']);
        $service = Service::factory()->create(['node_id' => $source->id]);

        return [app(ContainerMigrationProgress::class), $service, $source, $target];
    }
}
