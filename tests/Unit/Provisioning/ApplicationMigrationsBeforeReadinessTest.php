<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDatabaseMigrationService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerMigrationPlan;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Service 457 sat for two days on "Split web/API stack did not become ready".
 * Its health route needed tables its migrations create, and the pull judged
 * health before it ran migrations, so nothing could ever pass. Migrations now
 * run once the process is listening and before anything asks if it is well.
 */
class ApplicationMigrationsBeforeReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_process_is_waited_for_and_the_migration_runs_before_any_readiness_verdict(): void
    {
        [$service, $deployment] = $this->service(['node_workloads' => ['topology' => 'split_web_api', 'backend' => ['root' => 'apps/backend']]]);
        $plan = new ContainerMigrationPlan(tool: 'Alembic', command: 'alembic upgrade head', workDir: '/app/apps/backend', source: 'alembic.ini');
        $ssh = Mockery::mock(SSHService::class);
        $order = [];

        $deployments = Mockery::mock(ContainerDeploymentService::class);
        $deployments->shouldReceive('waitForApplicationListening')
            ->once()
            ->with($ssh, Mockery::type(ContainerDeployment::class), '/api/health')
            ->andReturnUsing(function () use (&$order): void {
                $order[] = 'listening';
            });

        $migrations = Mockery::mock(ContainerDatabaseMigrationService::class)->makePartial();
        $migrations->shouldReceive('plan')->once()->andReturn($plan);
        $migrations->shouldReceive('run')
            ->once()
            ->with($service, $deployment, $ssh, $plan, true)
            ->andReturnUsing(function () use (&$order): array {
                $order[] = 'run';

                return ['output' => 'Running upgrade -> abc123', 'tables_before' => 0, 'tables_after' => 7];
            });

        $result = $migrations->runBeforeReadiness($service, $deployment, $ssh, $deployments, operationAlreadyLocked: true);

        $this->assertSame(['listening', 'run'], $order);
        $this->assertSame($plan, $result['plan']);
        $this->assertSame(7, $result['tables_after']);
    }

    #[Test]
    public function a_repository_without_a_migration_tool_is_not_waited_on(): void
    {
        [$service, $deployment] = $this->service();
        $ssh = Mockery::mock(SSHService::class);

        $deployments = Mockery::mock(ContainerDeploymentService::class);
        $deployments->shouldNotReceive('waitForApplicationListening');

        $migrations = Mockery::mock(ContainerDatabaseMigrationService::class)->makePartial();
        $migrations->shouldReceive('plan')->once()->andReturnNull();
        $migrations->shouldNotReceive('run');

        $this->assertNull($migrations->runBeforeReadiness($service, $deployment, $ssh, $deployments, operationAlreadyLocked: true));
    }

    #[Test]
    public function a_split_stack_is_asked_through_its_api_route_and_a_plain_one_at_its_root(): void
    {
        $migrations = new ContainerDatabaseMigrationService;

        [$split] = $this->service(['node_workloads' => ['topology' => 'split_web_api']]);
        [$plain] = $this->service();

        $this->assertSame('/api/health', $migrations->listeningPath($split));
        $this->assertSame('/', $migrations->listeningPath($plain));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function service(array $meta = []): array
    {
        $template = ContainerTemplate::where('slug', 'python')->first()
            ?? ContainerTemplate::factory()->create(['slug' => 'python']);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => array_merge(['provision_template_slug' => 'python'], $meta),
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python-'.uniqid(),
            'status' => 'running',
        ]);

        return [$service->fresh(), $deployment];
    }
}
