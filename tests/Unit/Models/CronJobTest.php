<?php

namespace Tests\Unit\Models;

use App\Models\CronJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CronJobTest extends TestCase
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

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
        });
    }

    public function test_refresh_next_run_at_persists_future_timestamp(): void
    {
        $job = CronJob::create([
            'name' => 'Test Job',
            'command' => 'cron:generate-invoices',
            'schedule' => '*/10 * * * *',
            'enabled' => true,
        ]);

        $job->refreshNextRunAt();

        $this->assertNotNull($job->fresh()->next_run_at);
        $this->assertTrue($job->fresh()->next_run_at->isFuture());
    }

    public function test_resolved_next_run_at_falls_back_to_expression(): void
    {
        $job = CronJob::create([
            'name' => 'Test Job',
            'command' => 'cron:mark-invoices-overdue',
            'schedule' => '0 3 * * *',
            'enabled' => true,
            'next_run_at' => null,
        ]);

        $this->assertNotNull($job->resolved_next_run_at);
    }
}
