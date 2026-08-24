<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DirectAdminToContainerMigrationEnvTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_replaces_existing_database_keys_and_appends_missing_ones(): void
    {
        $merged = app(DirectAdminToContainerMigrationService::class)->mergeEnvAssignments(
            "APP_NAME=Laravel\nDB_HOST=127.0.0.1\nDB_DATABASE=old\n",
            [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => 'db',
                'DB_PORT' => '3306',
                'DB_DATABASE' => 's24_db',
                'DB_USERNAME' => 'u74_s24',
            ],
        );

        $this->assertStringContainsString("APP_NAME=Laravel\n", $merged);
        $this->assertStringContainsString("DB_HOST=db\n", $merged);
        $this->assertStringContainsString("DB_DATABASE=s24_db\n", $merged);
        $this->assertStringContainsString("DB_CONNECTION=mysql\n", $merged);
        $this->assertStringContainsString("DB_USERNAME=u74_s24\n", $merged);
        $this->assertStringNotContainsString('127.0.0.1', $merged);
        $this->assertStringNotContainsString('DB_DATABASE=old', $merged);
    }

    #[Test]
    public function it_rewrites_env_over_sftp_instead_of_php_or_python_on_the_node(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn (string $command) => str_contains($command, 'test -f')))
            ->andReturn("yes\n");
        $ssh->shouldReceive('downloadFile')
            ->once()
            ->with('/opt/talksasa/containers/user-74-service-24-laravel/app/.env')
            ->andReturn("DB_HOST=127.0.0.1\n");
        $ssh->shouldReceive('upload')
            ->once()
            ->withArgs(function (string $contents, string $path): bool {
                return $path === '/opt/talksasa/containers/user-74-service-24-laravel/app/.env'
                    && str_contains($contents, 'DB_HOST=db')
                    && ! str_contains($contents, 'php -r')
                    && ! str_contains($contents, 'python3');
            });

        app(DirectAdminToContainerMigrationService::class)->rewriteAppEnvDatabase(
            $ssh,
            '/opt/talksasa/containers/user-74-service-24-laravel/app',
            ['DB_HOST' => 'db', 'DB_DATABASE' => 's24_db'],
        );

        $this->assertTrue(true);
    }
}
