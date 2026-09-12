<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDatabaseMigrationService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\Provisioning\ContainerMigrationPlan;
use App\Services\Provisioning\ContainerNodeBuildService;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\Provisioning\ContainerStackCommandService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every stack but Laravel installed its migration tool during a pull and then
 * never ran it. A Python API arrived with working credentials, an empty schema,
 * and an application whose every query failed — which the platform reported as
 * "did not become ready".
 */
class PythonPullMigrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function a_python_pull_runs_migrations_after_the_runtime_refresh(): void
    {
        // Order is the part that is easy to get wrong. The environment step
        // corrects DATABASE_URL, but the container only carries that once
        // compose has been rewritten and it has been recreated, so migrations
        // run any earlier would run against the previous environment.
        $steps = $this->stepsFor('python', runMigrations: true);

        $this->assertContains('migrations', $steps);
        $this->assertGreaterThan(
            array_search('runtime', $steps, true),
            array_search('migrations', $steps, true),
        );
        $this->assertLessThan(
            array_search('health', $steps, true),
            array_search('migrations', $steps, true),
        );
    }

    #[Test]
    public function a_pull_asked_to_skip_migrations_does_not_list_the_step(): void
    {
        $this->assertNotContains('migrations', $this->stepsFor('python', runMigrations: false));
    }

    #[Test]
    public function a_node_pull_gets_the_step_too(): void
    {
        // Prisma, Knex, Drizzle and Sequelize were all already readable from a
        // repository. Nothing ever ran them either.
        $this->assertContains('migrations', $this->stepsFor('nodejs', runMigrations: true));
    }

    #[Test]
    public function laravel_keeps_its_own_placement_and_gains_no_second_step(): void
    {
        $steps = $this->stepsFor('laravel', runMigrations: true);

        $this->assertSame(1, count(array_keys($steps, 'migrations', true)));
        $this->assertLessThan(
            array_search('frontend', $steps, true),
            array_search('migrations', $steps, true),
        );
    }

    #[Test]
    public function a_migration_run_inside_a_pull_does_not_block_on_the_lock_the_pull_holds(): void
    {
        // The pull already holds this service's node build lock. Blocking on a
        // lock the caller owns waits out the timeout and fails a migration that
        // nothing was competing for.
        [$service, $deployment] = $this->service('python');

        $held = Cache::lock(app(ContainerNodeBuildService::class)->lockName($service), 60);
        $this->assertTrue($held->get());

        $commands = Mockery::mock(ContainerStackCommandService::class);
        $commands->shouldReceive('resolveAppComposeService')->andReturn('backend');
        $commands->shouldReceive('execInContainer')->once()->andReturn('Running upgrade -> abc123');
        $this->app->instance(ContainerStackCommandService::class, $commands);

        $inspector = Mockery::mock(ContainerRuntimeInspector::class);
        $inspector->shouldReceive('inspect')->andReturn(['running' => true, 'restarting' => false, 'missing' => false]);
        $this->app->instance(ContainerRuntimeInspector::class, $inspector);

        $result = app(ContainerDatabaseMigrationService::class)->run(
            $service,
            $deployment,
            Mockery::mock(SSHService::class),
            new ContainerMigrationPlan(
                tool: 'Alembic',
                command: 'alembic upgrade head',
                workDir: '/app/apps/backend',
                source: 'alembic.ini',
            ),
            operationAlreadyLocked: true,
        );

        $this->assertStringContainsString('abc123', $result['output']);

        $held->release();
    }

    #[Test]
    public function a_database_that_is_still_starting_is_retried_and_a_bad_migration_is_not(): void
    {
        $method = new ReflectionMethod(ContainerDatabaseMigrationService::class, 'databaseIsNotReadyYet');
        $method->setAccessible(true);
        $service = app(ContainerDatabaseMigrationService::class);

        foreach ([
            'could not connect to server: Connection refused',
            'sqlalchemy.exc.OperationalError: connection refused',
            'the database system is starting up',
            'could not translate host name "db" to address',
            'SQLSTATE[HY000] [2002] No such file or directory',
        ] as $transient) {
            $this->assertTrue($method->invoke($service, $transient), $transient);
        }

        foreach ([
            'alembic.util.exc.CommandError: Cant locate revision identified by abc123',
            'psycopg2.errors.DuplicateTable: relation "users" already exists',
            'syntax error at or near "CREATE"',
        ] as $real) {
            // Running bad SQL five more times tells nobody anything and costs
            // five minutes of somebody's deploy.
            $this->assertFalse($method->invoke($service, $real), $real);
        }
    }

    /**
     * @return list<string>
     */
    private function stepsFor(string $slug, bool $runMigrations): array
    {
        [$service] = $this->service($slug);

        $method = new ReflectionMethod(ContainerGitRepositoryService::class, 'buildInitialSteps');
        $method->setAccessible(true);

        $steps = $method->invoke(
            app(ContainerGitRepositoryService::class),
            $service,
            true,
            $runMigrations,
        );

        return array_column($steps, 'key');
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function service(string $slug): array
    {
        // The seeded catalogue already holds some of these slugs, and the
        // column is unique.
        $template = ContainerTemplate::where('slug', $slug)->first()
            ?? ContainerTemplate::factory()->create(['slug' => $slug]);
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => [
                'provision_template_slug' => $slug,
                'node_workloads' => ['backend' => ['root' => 'apps/backend']],
            ],
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-'.$slug.'-'.uniqid(),
            'status' => 'running',
        ]);

        return [$service->fresh(), $deployment];
    }
}
