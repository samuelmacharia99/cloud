<?php

namespace Tests\Unit\Console;

use App\Console\Scheduling\ApplicationSchedule;
use App\Jobs\RunPlatformCronJob;
use App\Jobs\SendCronFailureAlerts;
use App\Models\CronJob;
use App\Models\Setting;
use App\Support\ScheduleLogRotator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

    public function test_backup_dispatch_uses_bounded_overlap_mutex(): void
    {
        $schedule = app(ApplicationSchedule::class);

        $this->assertSame(15, $schedule->overlapExpiresMinutes('cron:backup-containers'));
        $this->assertSame(60, $schedule->overlapExpiresMinutes('cron:mark-invoices-overdue'));
    }

    public function test_configuring_schedule_does_not_fake_scheduler_heartbeat(): void
    {
        Config::set('scheduler.enabled', true);
        Cache::forget('scheduler.last_heartbeat');
        Setting::query()->where('key', 'scheduler_last_heartbeat_at')->delete();

        app(ApplicationSchedule::class)->configure(app(Schedule::class));

        $this->assertNull(Cache::get('scheduler.last_heartbeat'));
        $this->assertNull(Setting::getValue('scheduler_last_heartbeat_at'));
    }

    public function test_cron_failure_alerts_are_dispatched_not_sent_inline(): void
    {
        Bus::fake();
        $job = CronJob::create([
            'name' => 'Failing job',
            'command' => 'cron:example',
            'schedule' => '* * * * *',
            'enabled' => true,
        ]);

        $method = new \ReflectionMethod(ApplicationSchedule::class, 'logCronFailure');
        $method->invoke(app(ApplicationSchedule::class), $job);

        Bus::assertDispatched(
            SendCronFailureAlerts::class,
            fn (SendCronFailureAlerts $alert) => $alert->cronJobId === $job->id
                && $alert->queue === 'default'
        );
    }

    public function test_due_platform_command_is_dispatched_instead_of_run_in_scheduler(): void
    {
        Bus::fake();
        Config::set('scheduler.enabled', true);
        Config::set('scheduler.use_on_one_server', false);
        $job = CronJob::create([
            'name' => 'Queued platform work',
            'command' => 'cron:mark-invoices-overdue',
            'schedule' => '* * * * *',
            'enabled' => true,
        ]);

        $schedule = app(Schedule::class);
        app(ApplicationSchedule::class)->configure($schedule);
        $event = collect($schedule->events())
            ->first(fn ($candidate) => str_contains(
                (string) ($candidate->description ?? ''),
                $job->command
            ));

        $this->assertNotNull($event);
        $event->run($this->app);

        Bus::assertDispatched(
            RunPlatformCronJob::class,
            fn (RunPlatformCronJob $queued) => $queued->cronJobId === $job->id
                && $queued->queue === 'platform-cron'
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
