<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerMigrationBundleService;
use App\Services\Provisioning\ContainerMigrationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ContainerMigrationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function successful_migration_cuts_over_only_after_verified_restore(): void
    {
        [$service, $deployment, $source, $target] = $this->migrationModels();
        [$migration, $bundle, $deploy] = $this->migrationService($source, $target);
        $bundle->expects($this->once())->method('preflight')->willReturn([
            'source_bytes' => 1000,
            'target_free_bytes' => 100000,
            'required_bytes' => 2000,
            'volumes' => ['service-db'],
        ]);
        $bundle->expects($this->once())->method('create')->willReturn($this->bundle());
        $bundle->expects($this->once())->method('transfer');
        $bundle->expects($this->once())->method('restore');
        $bundle->expects($this->exactly(2))->method('cleanup');
        $bundle->expects($this->once())->method('stopAndRemoveTarget');
        $deploy->expects($this->once())->method('ensureComposeFileExists');
        $deploy->expects($this->once())->method('startComposeStack');
        $deploy->expects($this->once())->method('waitForContainerRunning');
        $deploy->expects($this->once())->method('rebindDeploymentDomainsStrict');

        $receipt = $migration->migrate($service, $target, 'planned_maintenance');

        $this->assertSame($target->id, $service->fresh()->node_id);
        $this->assertSame($target->id, $deployment->fresh()->node_id);
        $this->assertSame(1, $receipt['volume_count']);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => 'migration_succeeded',
        ]);
    }

    #[Test]
    public function concurrent_container_operation_rejects_migration_before_preflight(): void
    {
        [$service, , $source, $target] = $this->migrationModels();
        [$migration, $bundle] = $this->migrationService($source, $target);
        $bundle->expects($this->never())->method('preflight');
        $lock = Cache::lock('container-operation:service:'.$service->id, 60);
        $this->assertTrue($lock->get());

        try {
            $migration->migrate($service, $target, 'manual');
            $this->fail('Expected the concurrent operation lock to reject migration.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function target_readiness_failure_restores_source_ownership_and_runtime(): void
    {
        [$service, $deployment, $source, $target] = $this->migrationModels();
        [$migration, $bundle, $deploy] = $this->migrationService($source, $target);
        $bundle->method('preflight')->willReturn([
            'source_bytes' => 1000,
            'target_free_bytes' => 100000,
            'required_bytes' => 2000,
            'volumes' => ['service-db'],
        ]);
        $bundle->method('create')->willReturn($this->bundle());
        $bundle->method('transfer');
        $bundle->method('restore');
        $bundle->method('cleanup');
        $bundle->expects($this->once())->method('stopAndRemoveTarget');
        $deploy->expects($this->exactly(2))->method('ensureComposeFileExists');
        $deploy->expects($this->exactly(2))->method('startComposeStack');
        $readinessCalls = 0;
        $deploy->expects($this->exactly(2))
            ->method('waitForContainerRunning')
            ->willReturnCallback(function () use (&$readinessCalls): void {
                $readinessCalls++;
                if ($readinessCalls === 1) {
                    throw new \RuntimeException('target unhealthy');
                }
            });

        try {
            $migration->migrate($service, $target, 'manual');
            $this->fail('Expected target readiness to fail.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('target unhealthy', $e->getMessage());
        }

        $this->assertSame($source->id, $service->fresh()->node_id);
        $this->assertSame($source->id, $deployment->fresh()->node_id);
        $this->assertSame('running', $deployment->fresh()->status);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => 'migration_rolled_back',
        ]);
    }

    #[Test]
    public function domain_cutover_failure_rolls_back_database_counters_and_source_routing(): void
    {
        [$service, $deployment, $source, $target] = $this->migrationModels();
        [$migration, $bundle, $deploy] = $this->migrationService($source, $target);
        $bundle->method('preflight')->willReturn([
            'source_bytes' => 1000,
            'source_free_bytes' => 100000,
            'target_free_bytes' => 100000,
            'required_bytes' => 2000,
            'volumes' => ['service-db'],
        ]);
        $bundle->method('create')->willReturn($this->bundle());
        $bundle->method('transfer');
        $bundle->method('restore');
        $bundle->method('cleanup');
        $bundle->expects($this->once())->method('stopAndRemoveTarget');
        $deploy->expects($this->exactly(2))->method('ensureComposeFileExists');
        $deploy->expects($this->exactly(2))->method('startComposeStack');
        $deploy->expects($this->exactly(2))->method('waitForContainerRunning');
        $rebindCalls = 0;
        $deploy->expects($this->exactly(2))
            ->method('rebindDeploymentDomainsStrict')
            ->willReturnCallback(function () use (&$rebindCalls): void {
                $rebindCalls++;
                if ($rebindCalls === 1) {
                    throw new \RuntimeException('proxy update failed');
                }
            });

        try {
            $migration->migrate($service, $target, 'rebalancing');
            $this->fail('Expected domain cutover to fail.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('proxy update failed', $e->getMessage());
        }

        $this->assertSame($source->id, $service->fresh()->node_id);
        $this->assertSame($source->id, $deployment->fresh()->node_id);
        $this->assertSame(1, $source->fresh()->container_count);
        $this->assertSame(0, $target->fresh()->container_count);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => 'migration_rolled_back',
        ]);
    }

    #[Test]
    public function rollback_failure_marks_the_service_and_deployment_failed(): void
    {
        [$service, $deployment, $source, $target] = $this->migrationModels();
        [$migration, $bundle, $deploy] = $this->migrationService($source, $target);
        $bundle->method('preflight')->willReturn([
            'source_bytes' => 1000,
            'target_free_bytes' => 100000,
            'required_bytes' => 2000,
            'volumes' => ['service-db'],
        ]);
        $bundle->method('create')->willReturn($this->bundle());
        $bundle->method('transfer');
        $bundle->method('restore');
        $bundle->method('cleanup');
        $bundle->method('stopAndRemoveTarget');
        $deploy->method('ensureComposeFileExists');
        $deploy->method('startComposeStack');
        $deploy->method('waitForContainerRunning')
            ->willThrowException(new \RuntimeException('runtime unavailable'));

        try {
            $migration->migrate($service, $target, 'manual');
            $this->fail('Expected migration and rollback to fail.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('source workload could not be restarted', $e->getMessage());
        }

        $this->assertSame('failed', $service->fresh()->status->value);
        $this->assertSame('failed', $deployment->fresh()->status);
    }

    /**
     * @return array{0: ContainerMigrationService, 1: ContainerMigrationBundleService&MockObject, 2: ContainerDeploymentService&MockObject}
     */
    private function migrationService(Node $source, Node $target): array
    {
        $sourceSsh = $this->createMock(SSHService::class);
        $targetSsh = $this->createMock(SSHService::class);
        $sourceSsh->method('disconnect');
        $targetSsh->method('disconnect');
        $bundle = $this->createMock(ContainerMigrationBundleService::class);
        $deploy = $this->createMock(ContainerDeploymentService::class);
        $migration = new ContainerMigrationService(
            $deploy,
            $bundle,
            fn (Node $node): SSHService => $node->id === $source->id ? $sourceSsh : $targetSsh,
        );

        return [$migration, $bundle, $deploy];
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment, 2: Node, 3: Node}
     */
    private function migrationModels(): array
    {
        $user = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nginx-migration-test',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $source = Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'container_count' => 1,
        ]);
        $target = Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'container_count' => 0,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $source->id,
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $source->id,
            'container_name' => 'migration-service-'.$service->id,
            'status' => 'running',
        ]);

        return [$service->fresh(['containerDeployment.node', 'product.containerTemplate']), $deployment, $source, $target];
    }

    /**
     * @return array<string, mixed>
     */
    private function bundle(): array
    {
        return [
            'archive' => '/tmp/migration.tar.gz',
            'checksum' => str_repeat('a', 64),
            'bytes' => 1000,
            'application' => [
                'file' => 'application.tar.gz',
                'checksum' => str_repeat('b', 64),
                'bytes' => 500,
            ],
            'volumes' => [[
                'name' => 'service-db',
                'file' => 'volume-001.tar.gz',
                'checksum' => str_repeat('c', 64),
                'bytes' => 500,
            ]],
        ];
    }
}
