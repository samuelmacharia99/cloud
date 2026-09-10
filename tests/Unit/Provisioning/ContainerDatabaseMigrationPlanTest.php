<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerDatabaseMigrationService;
use App\Services\Provisioning\ContainerMigrationPlan;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration command is read from the repository, so every case here is a
 * statement about which files were on disk and what that means.
 */
class ContainerDatabaseMigrationPlanTest extends TestCase
{
    #[Test]
    public function a_declared_script_wins_because_the_author_chose_it(): void
    {
        $plan = $this->service()->declaredScriptPlan(
            json_encode(['scripts' => ['migrate' => 'prisma migrate deploy', 'build' => 'tsc']]),
            'npm',
            '/app',
        );

        $this->assertNotNull($plan);
        $this->assertSame('npm run migrate', $plan->command);
        $this->assertStringContainsString('scripts.migrate', $plan->source);
    }

    #[Test]
    public function it_runs_a_declared_script_with_the_project_package_manager(): void
    {
        $migrations = $this->service();
        $packageJson = json_encode(['scripts' => ['db:migrate' => 'knex migrate:latest']]);

        $this->assertSame('pnpm run db:migrate', $migrations->declaredScriptPlan($packageJson, 'pnpm', '/app')->command);
        $this->assertSame('yarn run db:migrate', $migrations->declaredScriptPlan($packageJson, 'yarn', '/app')->command);
    }

    #[Test]
    public function it_refuses_the_script_that_offers_to_reset_the_database(): void
    {
        $plan = $this->service()->declaredScriptPlan(
            json_encode(['scripts' => ['migrate' => 'prisma migrate dev']]),
            'npm',
            '/app',
        );

        $this->assertNull($plan);
    }

    #[Test]
    public function it_names_the_forms_that_destroy_data(): void
    {
        $migrations = $this->service();

        foreach ([
            'prisma migrate dev',
            'prisma migrate reset',
            'prisma db push --accept-data-loss',
            'php artisan migrate:fresh',
            'knex migrate:rollback',
        ] as $script) {
            $this->assertTrue($migrations->isUnsafeScript($script), $script.' should be refused');
        }

        foreach ([
            'prisma migrate deploy',
            'knex migrate:latest',
            'alembic upgrade head',
            'sequelize-cli db:migrate',
        ] as $script) {
            $this->assertFalse($migrations->isUnsafeScript($script), $script.' is safe to run unattended');
        }
    }

    #[Test]
    public function a_plan_with_no_environment_runs_as_written(): void
    {
        $plan = $this->plan('nodejs', [
            '/app/package.json' => '{}',
            '/app/knexfile.js' => 'module.exports = {}',
        ]);

        $this->assertSame($plan->command, $this->service()->commandWithEnvironment($plan));
    }

    #[Test]
    public function an_environment_travels_with_the_command_into_a_running_container(): void
    {
        $plan = $this->plan('ruby', ['/app/bin/rails' => 'ruby', '/app/db/migrate' => 'dir']);

        $this->assertSame(
            'env RAILS_ENV=production bin/rails db:migrate',
            $this->service()->commandWithEnvironment($plan),
        );
    }

    #[Test]
    public function prisma_with_committed_migrations_is_deployed_rather_than_pushed(): void
    {
        $plan = $this->plan('nodejs', [
            '/app/package.json' => '{"dependencies":{"@prisma/client":"5.0.0"}}',
            '/app/prisma/schema.prisma' => 'datasource db {}',
            '/app/prisma/migrations' => 'dir',
        ]);

        $this->assertSame('Prisma', $plan->tool);
        $this->assertSame('npx --yes prisma migrate deploy', $plan->command);
    }

    #[Test]
    public function a_prisma_schema_that_was_never_versioned_is_pushed_instead(): void
    {
        $plan = $this->plan('nodejs', [
            '/app/package.json' => '{"dependencies":{"@prisma/client":"5.0.0"}}',
            '/app/prisma/schema.prisma' => 'datasource db {}',
        ]);

        $this->assertSame('npx --yes prisma db push', $plan->command);
        $this->assertStringContainsString('without a migrations directory', $plan->source);
    }

    #[Test]
    public function it_recognises_knex_by_its_config_file(): void
    {
        $plan = $this->plan('nodejs', [
            '/app/package.json' => '{}',
            '/app/knexfile.js' => 'module.exports = {}',
        ]);

        $this->assertSame('Knex', $plan->tool);
        $this->assertSame('npx --yes knex migrate:latest', $plan->command);
    }

    #[Test]
    public function it_recognises_django_and_keeps_it_non_interactive(): void
    {
        $plan = $this->plan('python', ['/app/manage.py' => 'import django']);

        $this->assertSame('python manage.py migrate --noinput', $plan->command);
    }

    #[Test]
    public function it_recognises_rails_and_pins_the_environment(): void
    {
        $plan = $this->plan('ruby', ['/app/bin/rails' => '#!/usr/bin/env ruby', '/app/db/migrate' => 'dir']);

        $this->assertSame('bin/rails db:migrate', $plan->command);
        $this->assertSame(['RAILS_ENV' => 'production'], $plan->environment);
    }

    #[Test]
    public function it_probes_the_pinned_api_root_of_a_split_project(): void
    {
        $plan = $this->plan('nodejs', [
            '/app/apps/api/package.json' => '{}',
            '/app/apps/api/knexfile.ts' => 'export default {}',
        ], root: 'apps/api');

        $this->assertSame('/app/apps/api', $plan->workDir);
        $this->assertSame('npx --yes knex migrate:latest', $plan->command);
    }

    #[Test]
    public function an_application_with_no_migration_tool_gets_no_plan(): void
    {
        $this->assertNull($this->plan('nodejs', ['/app/package.json' => '{"scripts":{"start":"node index.js"}}']));
    }

    #[Test]
    public function a_stack_the_platform_cannot_migrate_gets_no_plan(): void
    {
        $this->assertNull($this->plan('wordpress', ['/app/index.php' => '<?php']));
    }

    private function service(): ContainerDatabaseMigrationService
    {
        return app(ContainerDatabaseMigrationService::class);
    }

    /**
     * @param  array<string, string>  $files  container path => contents, or 'dir'
     */
    private function plan(string $stack, array $files, string $root = ''): ?ContainerMigrationPlan
    {
        $template = new ContainerTemplate(['slug' => $stack]);
        $product = new Product;
        $product->setRelation('containerTemplate', $template);

        $service = new Service([
            'service_meta' => $root === '' ? [] : ['node_workloads' => ['backend' => ['root' => $root]]],
        ]);
        $service->id = 454;
        $service->setRelation('product', $product);

        $deployment = new ContainerDeployment;
        $deployment->container_name = 'user-493-service-454-'.$stack;
        $service->setRelation('containerDeployment', $deployment);

        $base = '/opt/talksasa/containers/'.$deployment->container_name.'/app';
        $files['/app'] = 'dir';
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) use ($files, $base): string {
            if (preg_match("/^test -([fd]) '(.+)' &&/", $command, $matches) === 1) {
                $path = str_replace($base, '/app', $matches[2]);
                $expected = $matches[1] === 'd' ? 'dir' : null;
                $found = $files[$path] ?? null;

                return $found !== null && ($expected === null ? $found !== 'dir' : $found === 'dir') ? 'yes' : 'no';
            }

            if (preg_match("/^head -c \d+ '(.+)'/", $command, $matches) === 1) {
                $path = str_replace($base, '/app', $matches[1]);

                return (string) ($files[$path] ?? '');
            }

            return '';
        });

        return $this->service()->plan($service, $deployment, $ssh);
    }
}
