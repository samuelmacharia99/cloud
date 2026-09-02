<?php

namespace Tests\Unit\Console\Commands;

use App\Services\Provisioning\ContainerCronService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunContainerCronJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_customer_job_failures_do_not_fail_the_platform_cron(): void
    {
        $this->mock(ContainerCronService::class, function ($mock) {
            $mock->shouldReceive('runDueJobs')->once()->andReturn([
                'processed' => 3,
                'dispatched' => 2,
                'failed' => 1,
                'skipped' => 0,
                'deferred' => 0,
            ]);
        });

        $this->artisan('cron:run-container-jobs')
            ->assertSuccessful()
            ->expectsOutputToContain('2 dispatched, 1 dispatch failures');
    }

    public function test_a_single_customer_dispatch_failure_does_not_fail_the_platform_cron(): void
    {
        $this->mock(ContainerCronService::class, function ($mock) {
            $mock->shouldReceive('runDueJobs')->once()->andReturn([
                'processed' => 1,
                'dispatched' => 0,
                'failed' => 1,
                'skipped' => 0,
                'deferred' => 0,
            ]);
        });

        $this->artisan('cron:run-container-jobs')
            ->assertSuccessful()
            ->expectsOutputToContain('0 dispatched, 1 dispatch failures');
    }

    public function test_total_batch_failure_marks_platform_cron_failed(): void
    {
        $this->mock(ContainerCronService::class, function ($mock) {
            $mock->shouldReceive('runDueJobs')->once()->andReturn([
                'processed' => 3,
                'dispatched' => 0,
                'failed' => 3,
                'skipped' => 0,
                'deferred' => 0,
            ]);
        });

        $this->artisan('cron:run-container-jobs')
            ->assertFailed()
            ->expectsOutputToContain('0 dispatched, 3 dispatch failures');
    }
}
