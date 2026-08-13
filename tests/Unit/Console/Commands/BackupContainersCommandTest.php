<?php

namespace Tests\Unit\Console\Commands;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class BackupContainersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defers_remaining_backups_when_runtime_budget_is_exhausted(): void
    {
        Carbon::setTestNow('2026-08-13 03:30:00');

        $node = Node::factory()->create(['type' => 'container_host', 'is_active' => true]);
        $template = ContainerTemplate::factory()->create();
        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $template->id,
        ]);

        foreach ([1, 2] as $i) {
            $user = User::factory()->create();
            $service = Service::factory()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'node_id' => $node->id,
            ]);
            ContainerDeployment::factory()->create([
                'service_id' => $service->id,
                'node_id' => $node->id,
                'status' => 'running',
                'container_name' => "user-{$i}-service-{$service->id}-app",
            ]);
        }

        $backupService = Mockery::mock(ContainerBackupService::class);
        $backupService->shouldReceive('createBackup')
            ->once()
            ->andReturnUsing(function (Service $service) {
                Carbon::setTestNow(now()->addSeconds(5));

                return ContainerBackup::factory()->create([
                    'service_id' => $service->id,
                    'container_deployment_id' => $service->containerDeployment->id,
                    'node_id' => $service->node_id,
                    'status' => 'completed',
                    'size_bytes' => 1024,
                    'completed_at' => now(),
                    'type' => 'scheduled',
                ]);
            });

        $this->app->instance(ContainerBackupService::class, $backupService);
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyContainerBackupCompleted')->once();
            $mock->shouldReceive('notifyContainerBackupFailed')->never();
        });

        $this->artisan('cron:backup-containers', ['--force' => true, '--max-runtime' => 3])
            ->assertSuccessful()
            ->expectsOutputToContain('deferred');
    }

    public function test_skips_services_with_backup_already_in_progress(): void
    {
        $node = Node::factory()->create(['type' => 'container_host', 'is_active' => true]);
        $template = ContainerTemplate::factory()->create();
        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $template->id,
        ]);
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        ContainerBackup::factory()->create([
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'node_id' => $node->id,
            'status' => 'running',
            'started_at' => now()->subMinutes(10),
        ]);

        $backupService = Mockery::mock(ContainerBackupService::class);
        $backupService->shouldReceive('createBackup')->never();
        $this->app->instance(ContainerBackupService::class, $backupService);

        $this->artisan('cron:backup-containers', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('backup already in progress');
    }
}
