<?php

namespace Tests\Unit\Provisioning;

use App\Models\DatabaseTemplate;
use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ContainerDatabaseEnvironmentTest extends TestCase
{
    public function test_mysql_environment_uses_single_password_for_app_and_sidecar(): void
    {
        $vars = $this->invokeDatabaseEnvironmentVariables('mysql', [
            'DB_PASSWORD' => 'secret-app-password',
            'MYSQL_DATABASE' => 'myapp',
            'MYSQL_USER' => 'myuser',
        ]);

        $this->assertSame('secret-app-password', $vars['DB_PASSWORD']);
        $this->assertSame('secret-app-password', $vars['MYSQL_PASSWORD']);
        $this->assertSame('myapp', $vars['DB_DATABASE']);
        $this->assertSame('myuser', $vars['DB_USERNAME']);
        $this->assertSame('db', $vars['DB_HOST']);
    }

    public function test_postgresql_environment_sets_laravel_style_vars(): void
    {
        $vars = $this->invokeDatabaseEnvironmentVariables('postgresql', [
            'POSTGRES_PASSWORD' => 'pg-secret',
            'POSTGRES_DB' => 'appdb',
            'POSTGRES_USER' => 'appuser',
        ]);

        $this->assertSame('pg-secret', $vars['DB_PASSWORD']);
        $this->assertSame('pgsql', $vars['DB_CONNECTION']);
        $this->assertStringContainsString('postgresql://appuser:', $vars['DATABASE_URL']);
        $this->assertStringContainsString('pg-secret', $vars['DATABASE_URL']);
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function invokeDatabaseEnvironmentVariables(string $type, array $env): array
    {
        $template = new DatabaseTemplate([
            'type' => $type,
            'docker_image' => $type === 'postgresql' ? 'postgres:16-alpine' : 'mysql:8.0',
        ]);

        $service = new ContainerDeploymentService;
        $method = new ReflectionMethod(ContainerDeploymentService::class, 'databaseEnvironmentVariables');
        $method->setAccessible(true);

        return $method->invoke($service, $template, $env);
    }
}
