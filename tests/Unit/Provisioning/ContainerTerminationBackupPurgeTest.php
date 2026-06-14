<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerBackupService;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ContainerTerminationBackupPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_all_for_service_removes_backup_database_rows(): void
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => null,
            'status' => 'running',
        ]);

        $backup = ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => null,
            'backup_path' => null,
            'status' => 'completed',
        ]);

        app(ContainerBackupService::class)->purgeAllForService($service->fresh());

        $this->assertDatabaseMissing('container_backups', ['id' => $backup->id]);
    }

    public function test_terminate_purges_backups_for_service(): void
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => null,
            'status' => 'running',
        ]);

        $this->mock(ContainerBackupService::class, function ($mock) use ($service) {
            $mock->shouldReceive('purgeAllForService')
                ->once()
                ->with(\Mockery::on(fn ($record) => $record->id === $service->id));
        });

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'purgeBackupsForService');
        $method->setAccessible(true);
        $method->invoke(app(ContainerDeploymentService::class), $service->fresh());
    }
}
