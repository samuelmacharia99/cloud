<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PythonDatabaseDriverService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Service 457 crash-looped with "The asyncio extension requires an async driver
 * to be used. The loaded 'psycopg2' is not async."
 *
 * The platform composes DATABASE_URL and always wrote a bare `postgresql://`,
 * which SQLAlchemy resolves to psycopg2. An application built on
 * create_async_engine cannot import against that, so uvicorn died before it
 * bound a port. Naming the driver is the platform's job because the URL is.
 */
class PythonDatabaseDriverServiceTest extends TestCase
{
    private const APP_PATH = '/home/user-493/app';

    private const BACKEND_ROOT = 'apps/backend';

    private PythonDatabaseDriverService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PythonDatabaseDriverService;
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_names_the_async_driver_when_the_app_opens_an_async_engine_and_ships_one(): void
    {
        $env = ['DATABASE_URL' => 'postgresql://app:pw@db:5432/appdb'];

        $outcome = $this->service->align(
            $this->node(async: true, requirements: "fastapi\nsqlalchemy\nasyncpg==0.29.0\npsycopg2-binary\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('aligned', $outcome['status']);
        $this->assertSame('asyncpg', $outcome['driver']);
        $this->assertSame('postgresql+asyncpg://app:pw@db:5432/appdb', $env['DATABASE_URL']);
    }

    #[Test]
    public function it_keeps_the_synchronous_twin_synchronous_for_alembic(): void
    {
        // A stack that runs migrations needs both spellings. Alembic cannot use
        // an async driver any more than the engine can use a synchronous one.
        $env = ['DATABASE_URL' => 'postgresql://app:pw@db:5432/appdb'];

        $this->service->align(
            $this->node(async: true, requirements: "sqlalchemy\nasyncpg\nalembic\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('postgresql+asyncpg://app:pw@db:5432/appdb', $env['DATABASE_URL']);
        $this->assertSame('postgresql://app:pw@db:5432/appdb', $env['SYNC_DATABASE_URL']);
    }

    #[Test]
    public function it_leaves_a_synchronous_application_alone(): void
    {
        // Pinning asyncpg for an app that never asks for an async engine breaks
        // a stack that works today.
        $env = ['DATABASE_URL' => 'postgresql://app:pw@db:5432/appdb'];

        $outcome = $this->service->align(
            $this->node(async: false, requirements: "django\npsycopg2-binary\nasyncpg\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('synchronous', $outcome['status']);
        $this->assertSame('postgresql://app:pw@db:5432/appdb', $env['DATABASE_URL']);
    }

    #[Test]
    public function it_refuses_to_name_a_driver_the_application_does_not_ship(): void
    {
        // Naming a package the image does not have swaps this crash for a
        // ModuleNotFoundError one layer deeper, which reads worse, not better.
        $env = ['DATABASE_URL' => 'postgresql://app:pw@db:5432/appdb'];

        $outcome = $this->service->align(
            $this->node(async: true, requirements: "fastapi\nsqlalchemy\npsycopg2-binary\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('driver_missing', $outcome['status']);
        $this->assertNull($outcome['driver']);
        $this->assertSame('postgresql://app:pw@db:5432/appdb', $env['DATABASE_URL']);
        $this->assertStringContainsString('asyncpg', $outcome['message']);
    }

    #[Test]
    public function it_leaves_a_url_that_already_names_a_driver_alone(): void
    {
        $env = ['DATABASE_URL' => 'postgresql+psycopg://app:pw@db:5432/appdb'];

        $outcome = $this->service->align(
            $this->node(async: true, requirements: "asyncpg\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('already_pinned', $outcome['status']);
        $this->assertSame('postgresql+psycopg://app:pw@db:5432/appdb', $env['DATABASE_URL']);
        $this->assertSame('postgresql://app:pw@db:5432/appdb', $env['SYNC_DATABASE_URL']);
    }

    #[Test]
    public function it_prefers_asyncmy_over_aiomysql_when_a_mysql_stack_ships_both(): void
    {
        $env = ['DATABASE_URL' => 'mysql://app:pw@db:3306/appdb'];

        $outcome = $this->service->align(
            $this->node(async: true, requirements: "aiomysql\nasyncmy\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('asyncmy', $outcome['driver']);
        $this->assertSame('mysql+asyncmy://app:pw@db:3306/appdb', $env['DATABASE_URL']);
    }

    #[Test]
    public function it_does_not_mistake_a_longer_package_name_for_the_driver(): void
    {
        // "asyncpg-listen" and "asyncpg-stubs" are real packages, and neither
        // is a SQLAlchemy dialect.
        $this->assertFalse($this->service->declaresPackage("asyncpg-listen==0.3.0\n", 'asyncpg'));
        $this->assertFalse($this->service->declaresPackage("sqlalchemy-asyncpgx\n", 'asyncpg'));
        $this->assertTrue($this->service->declaresPackage("asyncpg==0.29.0\n", 'asyncpg'));
        $this->assertTrue($this->service->declaresPackage("asyncpg\n", 'asyncpg'));
        $this->assertTrue($this->service->declaresPackage("asyncpg = \"^0.29\"\n", 'asyncpg'));
    }

    #[Test]
    public function it_replaces_a_stale_async_value_left_in_the_synchronous_twin(): void
    {
        // The platform used to derive SYNC_DATABASE_URL by stripping a suffix
        // that was never there, so the two keys held the same string. A copy
        // carrying a driver is not a synchronous URL.
        $env = [
            'DATABASE_URL' => 'postgresql://app:pw@db:5432/appdb',
            'SYNC_DATABASE_URL' => 'postgresql+asyncpg://app:pw@db:5432/appdb',
        ];

        $this->service->align(
            $this->node(async: false, requirements: "django\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('postgresql://app:pw@db:5432/appdb', $env['SYNC_DATABASE_URL']);
    }

    #[Test]
    public function it_leaves_a_synchronous_twin_the_customer_pointed_elsewhere_alone(): void
    {
        $env = [
            'DATABASE_URL' => 'postgresql://app:pw@db:5432/appdb',
            'SYNC_DATABASE_URL' => 'postgresql://reporting:pw@reporting-db:5432/warehouse',
        ];

        $this->service->align(
            $this->node(async: true, requirements: "asyncpg\n"),
            self::APP_PATH,
            self::BACKEND_ROOT,
            $env,
        );

        $this->assertSame('postgresql://reporting:pw@reporting-db:5432/warehouse', $env['SYNC_DATABASE_URL']);
    }

    #[Test]
    public function it_does_nothing_without_a_database_url(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('exec');
        $env = [];

        $outcome = $this->service->align($ssh, self::APP_PATH, self::BACKEND_ROOT, $env);

        $this->assertSame('no_url', $outcome['status']);
        $this->assertSame([], $env);
    }

    #[Test]
    public function it_does_not_touch_a_scheme_it_has_no_async_driver_for(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('exec');
        $env = ['DATABASE_URL' => 'sqlite:///./app.db'];

        $outcome = $this->service->align($ssh, self::APP_PATH, self::BACKEND_ROOT, $env);

        $this->assertSame('unsupported_scheme', $outcome['status']);
        $this->assertSame('sqlite:///./app.db', $env['DATABASE_URL']);
    }

    /**
     * A node whose backend root holds the given requirements file, and which
     * either does or does not call create_async_engine anywhere in its Python.
     */
    private function node(bool $async, string $requirements): SSHService
    {
        $ssh = Mockery::mock(SSHService::class);
        $backend = self::APP_PATH.'/'.self::BACKEND_ROOT;

        $ssh->shouldReceive('exec')->andReturnUsing(
            function (string $command) use ($async, $requirements, $backend): string {
                if (str_contains($command, PythonDatabaseDriverService::ASYNC_ENGINE_CALL)) {
                    return $async && str_contains($command, $backend)
                        ? $backend.'/app/db/session.py'
                        : '';
                }

                if (str_contains($command, $backend.'/requirements.txt')) {
                    return $requirements;
                }

                return '';
            }
        );

        return $ssh;
    }
}
