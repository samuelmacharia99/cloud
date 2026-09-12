<?php

namespace Tests\Unit\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDatabaseMigrationService;
use App\Services\Provisioning\ContainerDatabaseSidecarResolver;
use App\Services\Provisioning\ContainerMigrationPlan;
use App\Services\Provisioning\ContainerPostgresExtensionService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\ContainerStackCommandService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Service 457's Alembic chain stops at a migration that refuses to run
 * without pgvector, and the stock sidecar does not ship it. That is not a
 * broken migration; it is a database missing something. The runner now
 * supplies the extension the failure names and lets the migration try again.
 */
class MigrationEnablesMissingExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const PGVECTOR_REFUSAL = "sqlalchemy.exc.InternalError: (psycopg2.errors.RaiseException) pgvector extension is required before this migration\nCONTEXT:  PL/pgSQL function inline_code_block line 4 at RAISE";

    #[Test]
    public function the_extension_a_migration_was_missing_is_enabled_and_the_migration_runs_again(): void
    {
        [$service, $deployment] = $this->stack();
        $this->sidecarIs('postgresql');
        $this->execAnswers([
            fn () => throw new SSHCommandException('alembic upgrade head', self::PGVECTOR_REFUSAL),
            fn () => 'Running upgrade 7b1c -> c31f8a7b2d04, migrate embeddings to pgvector',
        ]);

        $extensions = Mockery::mock(ContainerPostgresExtensionService::class)->makePartial();
        $extensions->shouldReceive('enableExtension')
            ->once()
            ->withArgs(fn (Service $s, ContainerDeployment $d, SSHService $ssh, string $name): bool => $s->is($service) && $name === 'vector')
            ->andReturn('pgvector is available in this database. The database now runs pgvector/pgvector:pg16.');
        $this->app->instance(ContainerPostgresExtensionService::class, $extensions);

        $result = app(ContainerDatabaseMigrationService::class)->run(
            $service,
            $deployment,
            Mockery::mock(SSHService::class)->shouldReceive('exec')->andReturn('')->getMock(),
            $this->plan(),
            operationAlreadyLocked: true,
        );

        $this->assertStringContainsString('pgvector is available in this database.', $result['output']);
        $this->assertStringContainsString('c31f8a7b2d04', $result['output']);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => 'database_extension_enabled',
        ]);
    }

    #[Test]
    public function any_other_failure_is_reported_as_it_was_and_nothing_is_enabled(): void
    {
        [$service, $deployment] = $this->stack();
        $this->sidecarIs('postgresql');
        $this->execAnswers([
            fn () => throw new SSHCommandException('alembic upgrade head', 'sqlalchemy.exc.ProgrammingError: relation "users" does not exist'),
        ]);

        $extensions = Mockery::mock(ContainerPostgresExtensionService::class)->makePartial();
        $extensions->shouldNotReceive('enableExtension');
        $this->app->instance(ContainerPostgresExtensionService::class, $extensions);

        $this->expectException(SSHCommandException::class);
        $this->expectExceptionMessage('relation "users" does not exist');

        app(ContainerDatabaseMigrationService::class)->run(
            $service,
            $deployment,
            Mockery::mock(SSHService::class)->shouldReceive('exec')->andReturn('')->getMock(),
            $this->plan(),
            operationAlreadyLocked: true,
        );
    }

    #[Test]
    public function a_mysql_sidecar_never_gets_a_postgres_extension(): void
    {
        [$service, $deployment] = $this->stack();
        $this->sidecarIs('mysql');
        $this->execAnswers([
            fn () => throw new SSHCommandException('alembic upgrade head', self::PGVECTOR_REFUSAL),
        ]);

        $extensions = Mockery::mock(ContainerPostgresExtensionService::class)->makePartial();
        $extensions->shouldNotReceive('enableExtension');
        $this->app->instance(ContainerPostgresExtensionService::class, $extensions);

        $this->expectException(SSHCommandException::class);

        app(ContainerDatabaseMigrationService::class)->run(
            $service,
            $deployment,
            Mockery::mock(SSHService::class)->shouldReceive('exec')->andReturn('')->getMock(),
            $this->plan(),
            operationAlreadyLocked: true,
        );
    }

    /**
     * @param  list<callable(): string>  $answers
     */
    private function execAnswers(array $answers): void
    {
        $commands = Mockery::mock(ContainerStackCommandService::class);
        $commands->shouldReceive('resolveAppComposeService')->andReturn('backend');
        $commands->shouldReceive('execInContainer')->times(count($answers))->andReturnUsing(function () use (&$answers): string {
            $next = array_shift($answers);

            return $next();
        });
        $this->app->instance(ContainerStackCommandService::class, $commands);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn(['running' => true, 'restarting' => false, 'missing' => false]);
        $this->app->instance(ContainerRuntimeInspector::class, $inspector);
    }

    private function sidecarIs(string $type): void
    {
        $resolver = Mockery::mock(ContainerDatabaseSidecarResolver::class);
        $resolver->shouldReceive('typeForService')->andReturn($type);
        $this->app->instance(ContainerDatabaseSidecarResolver::class, $resolver);
    }

    private function plan(): ContainerMigrationPlan
    {
        return new ContainerMigrationPlan(tool: 'Alembic', command: 'alembic upgrade head', workDir: '/app/apps/backend', source: 'alembic.ini');
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function stack(): array
    {
        $template = ContainerTemplate::where('slug', 'python')->first()
            ?? ContainerTemplate::factory()->create(['slug' => 'python']);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'python', 'node_workloads' => ['backend' => ['root' => 'apps/backend']]],
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
