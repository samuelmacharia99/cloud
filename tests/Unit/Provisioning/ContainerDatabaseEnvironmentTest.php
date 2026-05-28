<?php

namespace Tests\Unit\Provisioning;

use App\Models\DatabaseTemplate;
use App\Models\Service;
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

    public function test_mysql_environment_generates_per_service_identifiers_by_default(): void
    {
        $vars = $this->invokeDatabaseEnvironmentVariables('mysql', [], serviceId: 67, userId: 4);

        $this->assertSame('s67_db', $vars['DB_DATABASE']);
        $this->assertSame('s67_db', $vars['MYSQL_DATABASE']);
        $this->assertSame('u4_s67', $vars['DB_USERNAME']);
        $this->assertSame('u4_s67', $vars['MYSQL_USER']);
    }

    public function test_postgresql_environment_sets_laravel_style_vars(): void
    {
        $vars = $this->invokeDatabaseEnvironmentVariables('postgresql', [
            'POSTGRES_PASSWORD' => 'pg-secret',
            'POSTGRES_DB' => 'myapp',
            'POSTGRES_USER' => 'myuser',
        ]);

        $this->assertSame('pg-secret', $vars['DB_PASSWORD']);
        $this->assertSame('pgsql', $vars['DB_CONNECTION']);
        $this->assertStringContainsString('postgresql://myuser:', $vars['DATABASE_URL']);
        $this->assertStringContainsString('pg-secret', $vars['DATABASE_URL']);
    }

    public function test_postgresql_environment_generates_per_service_identifiers_by_default(): void
    {
        $vars = $this->invokeDatabaseEnvironmentVariables('postgresql', [], serviceId: 12, userId: 3);

        $this->assertSame('s12_db', $vars['POSTGRES_DB']);
        $this->assertSame('u3_s12', $vars['POSTGRES_USER']);
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function invokeDatabaseEnvironmentVariables(
        string $type,
        array $env,
        int $serviceId = 1,
        int $userId = 1
    ): array {
        $template = new DatabaseTemplate([
            'type' => $type,
            'docker_image' => $type === 'postgresql' ? 'postgres:16-alpine' : 'mysql:8.0',
        ]);

        $service = new Service;
        $service->id = $serviceId;
        $service->user_id = $userId;

        $deployer = new ContainerDeploymentService;
        $method = new ReflectionMethod(ContainerDeploymentService::class, 'databaseEnvironmentVariables');
        $method->setAccessible(true);

        return $method->invoke($deployer, $template, $env, $service);
    }
}
