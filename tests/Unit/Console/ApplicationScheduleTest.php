<?php

namespace Tests\Unit\Console;

use App\Console\Scheduling\ApplicationSchedule;
use App\Models\CronJob;
use App\Support\ScheduleLogRotator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApplicationScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_disabled_registers_no_database_jobs(): void
    {
        Config::set('scheduler.enabled', false);

        CronJob::create([
            'name' => 'Collect Container Metrics',
            'description' => 'Test',
            'command' => 'cron:collect-container-metrics',
            'schedule' => '*/5 * * * *',
            'enabled' => true,
        ]);

        $schedule = app(Schedule::class);
        app(ApplicationSchedule::class)->configure($schedule);

        $this->assertCount(0, $schedule->events());
    }

    public function test_local_environment_skips_heavy_jobs(): void
    {
        Config::set('scheduler.enabled', true);
        $this->app->detectEnvironment(fn () => 'local');

        CronJob::create([
            'name' => 'Collect Container Metrics',
            'description' => 'Test',
            'command' => 'cron:collect-container-metrics',
            'schedule' => '*/5 * * * *',
            'enabled' => true,
        ]);

        CronJob::create([
            'name' => 'Mark Invoices Overdue',
            'description' => 'Test',
            'command' => 'cron:mark-invoices-overdue',
            'schedule' => '0 3 * * *',
            'enabled' => true,
        ]);

        $schedule = app(Schedule::class);
        app(ApplicationSchedule::class)->configure($schedule);

        $commands = collect($schedule->events())
            ->map(fn ($event) => (string) ($event->command ?? $event->description ?? ''))
            ->all();

        $this->assertFalse(
            collect($commands)->contains(fn ($cmd) => str_contains($cmd, 'cron:collect-container-metrics')),
            'Heavy metrics job should be skipped in local'
        );
        $this->assertTrue(
            collect($commands)->contains(fn ($cmd) => str_contains($cmd, 'cron:mark-invoices-overdue')),
            'Lightweight jobs should still register in local'
        );
    }

    public function test_schedule_log_rotator_truncates_oversized_file(): void
    {
        Config::set('scheduler.cron_log_max_bytes', 100);

        $path = storage_path('logs/cron-test-rotate.log');
        file_put_contents($path, str_repeat('x', 200));

        $rotated = ScheduleLogRotator::rotateIfNeeded($path);

        $this->assertTrue($rotated);
        $this->assertFileExists($path);
        $this->assertLessThanOrEqual(1, filesize($path));

        @unlink($path);
        foreach (glob(storage_path('logs/cron-test-rotate.log.*.bak')) ?: [] as $bak) {
            @unlink($bak);
        }
    }
}
