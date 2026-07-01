<?php

namespace Tests\Unit\Services\Cron;

use App\Models\CronJob;
use App\Services\Cron\SchedulerHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulerHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('command')->unique();
            $table->string('schedule');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_ran_at')->nullable();
            $table->string('last_status')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cron_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cron_job_id');
            $table->string('status');
            $table->text('output')->nullable();
            $table->text('exception')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
        });
    }

    public function test_reports_unhealthy_when_scheduler_disabled(): void
    {
        Config::set('scheduler.enabled', false);

        CronJob::create([
            'name' => 'Invoices',
            'command' => 'cron:generate-invoices',
            'schedule' => '0 2 * * *',
            'enabled' => true,
            'next_run_at' => now()->addHour(),
        ]);

        $status = app(SchedulerHealthService::class)->status();

        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('disabled', implode(' ', $status['issues']));
    }

    public function test_reports_healthy_with_fresh_heartbeat(): void
    {
        Config::set('scheduler.enabled', true);
        Config::set('cache.default', 'file');
        Cache::put('scheduler.last_heartbeat', now()->toIso8601String(), now()->addMinutes(5));

        CronJob::create([
            'name' => 'Invoices',
            'command' => 'cron:generate-invoices',
            'schedule' => '0 2 * * *',
            'enabled' => true,
            'next_run_at' => now()->addHour(),
        ]);

        $status = app(SchedulerHealthService::class)->status();

        $this->assertTrue($status['healthy'], implode('; ', $status['issues'] ?? []));
        $this->assertTrue($status['heartbeat_fresh']);
    }
}
