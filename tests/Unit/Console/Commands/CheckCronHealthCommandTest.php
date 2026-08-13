<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\CheckCronHealthCommand;
use App\Models\CronJob;
use App\Models\CronJobLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_backup_containers_uses_extended_hang_threshold(): void
    {
        $command = new CheckCronHealthCommand;
        $job = new CronJob(['command' => 'cron:backup-containers']);

        $this->assertSame(14400, $command->maxRuntimeSeconds($job, 120));
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
}
