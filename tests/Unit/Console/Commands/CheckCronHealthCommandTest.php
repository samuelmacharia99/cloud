<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\CheckCronHealthCommand;
use App\Models\ContainerCronJob;
use App\Models\ContainerCronJobRun;
use App\Models\CronJob;
use App\Models\CronJobLog;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckCronHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_container_jobs_uses_extended_hang_threshold(): void
    {
        $command = new CheckCronHealthCommand;
        $job = new CronJob(['command' => 'cron:run-container-jobs']);

        $this->assertSame(600, $command->maxRuntimeSeconds($job, 120));
    }

    public function test_hang_threshold_ignores_command_options(): void
    {
        $command = new CheckCronHealthCommand;
        $job = new CronJob(['command' => 'cron:sync-service-live-status --heal']);

        $this->assertSame(900, $command->maxRuntimeSeconds($job, 120));
    }

    public function test_live_status_sync_running_under_threshold_is_not_marked_hung(): void
    {
        Setting::updateOrCreate(
            ['key' => 'max_execution_time'],
            ['value' => '120', 'description' => 'test']
        );

        $job = CronJob::create([
            'name' => 'Sync Service Live Status',
            'command' => 'cron:sync-service-live-status --heal',
            'schedule' => '*/15 * * * *',
            'enabled' => true,
        ]);

        CronJobLog::create([
            'cron_job_id' => $job->id,
            'status' => 'running',
            'started_at' => now()->subSeconds(303),
        ]);

        $this->artisan('cron:check-health')
            ->assertSuccessful()
            ->expectsOutputToContain('no issues detected');

        $this->assertDatabaseHas('cron_job_logs', [
            'cron_job_id' => $job->id,
            'status' => 'running',
        ]);
    }

    public function test_backup_dispatch_uses_bounded_hang_threshold(): void
    {
        $command = new CheckCronHealthCommand;
        $job = new CronJob(['command' => 'cron:backup-containers']);

        $this->assertSame(600, $command->maxRuntimeSeconds($job, 120));
    }

    public function test_hung_backup_job_marks_cron_job_failed_after_double_threshold(): void
    {
        Mail::fake();

        Setting::updateOrCreate(
            ['key' => 'max_execution_time'],
            ['value' => '120', 'description' => 'test']
        );

        $job = CronJob::create([
            'name' => 'Backup Containers',
            'command' => 'cron:backup-containers',
            'schedule' => '30 3 * * *',
            'enabled' => true,
            'last_status' => 'running',
        ]);

        CronJobLog::create([
            'cron_job_id' => $job->id,
            'status' => 'running',
            'started_at' => now()->subSeconds(30000),
        ]);

        $this->artisan('cron:check-health')->assertSuccessful();

        $this->assertDatabaseHas('cron_job_logs', [
            'cron_job_id' => $job->id,
            'status' => 'failed',
        ]);
        $this->assertSame('failed', $job->fresh()->last_status);
    }

    public function test_recovered_job_does_not_alert_on_historical_hour_failures(): void
    {
        Mail::fake();

        $job = CronJob::create([
            'name' => 'Example Recovered Job',
            'command' => 'cron:example-recovered-job',
            'schedule' => '* * * * *',
            'enabled' => true,
            'last_status' => 'success',
        ]);

        foreach ([50, 40, 30] as $minutesAgo) {
            CronJobLog::create([
                'cron_job_id' => $job->id,
                'status' => 'failed',
                'started_at' => now()->subMinutes($minutesAgo),
                'finished_at' => now()->subMinutes($minutesAgo - 1),
            ]);
        }

        CronJobLog::create([
            'cron_job_id' => $job->id,
            'status' => 'success',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'output' => 'ok',
        ]);

        $this->artisan('cron:check-health')
            ->assertSuccessful()
            ->expectsOutputToContain('no issues detected');
    }

    public function test_still_failing_job_alerts_when_three_failures_in_last_hour(): void
    {
        Mail::fake();

        $job = CronJob::create([
            'name' => 'Example Failing Job',
            'command' => 'cron:example-failing-job',
            'schedule' => '* * * * *',
            'enabled' => true,
            'last_status' => 'failed',
        ]);

        foreach ([50, 40, 5] as $minutesAgo) {
            CronJobLog::create([
                'cron_job_id' => $job->id,
                'status' => 'failed',
                'started_at' => now()->subMinutes($minutesAgo),
                'finished_at' => now()->subMinutes($minutesAgo - 1),
            ]);
        }

        $this->artisan('cron:check-health')
            ->assertSuccessful()
            ->expectsOutputToContain('1 issue(s)');
    }

    public function test_unknown_command_uses_default_threshold(): void
    {
        $command = new CheckCronHealthCommand;
        $job = new CronJob(['command' => 'cron:mark-invoices-overdue']);

        $this->assertSame(120, $command->maxRuntimeSeconds($job, 120));
    }

    public function test_backup_containers_running_under_threshold_is_not_marked_hung(): void
    {
        Setting::updateOrCreate(
            ['key' => 'max_execution_time'],
            ['value' => '120', 'description' => 'test']
        );

        $job = CronJob::create([
            'name' => 'Backup Containers',
            'command' => 'cron:backup-containers',
            'schedule' => '30 3 * * *',
            'enabled' => true,
        ]);

        CronJobLog::create([
            'cron_job_id' => $job->id,
            'status' => 'running',
            'started_at' => now()->subSeconds(300),
        ]);

        $this->artisan('cron:check-health')
            ->assertSuccessful();

        $this->assertDatabaseHas('cron_job_logs', [
            'cron_job_id' => $job->id,
            'status' => 'running',
        ]);
    }

    public function test_stale_queued_customer_cron_is_failed_and_unblocked(): void
    {
        Mail::fake();
        $service = Service::factory()->create();
        $job = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Queued too long',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
        ]);
        $run = ContainerCronJobRun::create([
            'container_cron_job_id' => $job->id,
            'attempt_uuid' => (string) Str::uuid(),
            'status' => 'queued',
            'started_at' => now()->subMinutes(10),
        ]);

        $this->artisan('cron:check-health')->assertSuccessful();

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $job->fresh()->last_status);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}
