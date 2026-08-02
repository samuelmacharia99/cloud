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

    public function test_normalize_database_environment_fixes_username_used_as_database(): void
    {
        $service = new Service;
        $service->id = 163;
        $service->user_id = 193;

        $result = (new ContainerDeploymentService)->normalizeDatabaseEnvironment($service, [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'u193_s163',
            'DB_USERNAME' => 'u193_s163',
            'DB_PASSWORD' => 'secret',
            'POSTGRES_DB' => 'u193_s163',
            'POSTGRES_USER' => 'u193_s163',
            'POSTGRES_PASSWORD' => 'secret',
        ], 'postgresql');

        $this->assertTrue($result['corrected']);
        $this->assertSame('u193_s163', $result['previous_database']);
        $this->assertSame('s163_db', $result['database']);
        $this->assertSame('s163_db', $result['env']['DB_DATABASE']);
        $this->assertSame('s163_db', $result['env']['POSTGRES_DB']);
        $this->assertSame('u193_s163', $result['env']['DB_USERNAME']);
        $this->assertStringContainsString('/s163_db', $result['env']['DATABASE_URL']);
    }

    public function test_normalize_database_environment_keeps_valid_custom_database_name(): void
    {
        $service = new Service;
        $service->id = 163;
        $service->user_id = 193;

        $result = (new ContainerDeploymentService)->normalizeDatabaseEnvironment($service, [
            'DB_DATABASE' => 'my_app',
            'DB_USERNAME' => 'u193_s163',
            'DB_PASSWORD' => 'secret',
        ], 'postgresql');

        $this->assertFalse($result['corrected']);
        $this->assertSame('my_app', $result['database']);
        $this->assertSame('my_app', $result['env']['DB_DATABASE']);
    }

    public function test_normalize_database_environment_aligns_mismatched_passwords(): void
    {
        $service = new Service;
        $service->id = 163;
        $service->user_id = 193;

        $result = (new ContainerDeploymentService)->normalizeDatabaseEnvironment($service, [
            'DB_DATABASE' => 's163_db',
            'DB_USERNAME' => 'u193_s163',
            'DB_PASSWORD' => 'panel-password',
            'POSTGRES_DB' => 's163_db',
            'POSTGRES_USER' => 'u193_s163',
            'POSTGRES_PASSWORD' => 'old-sidecar-password',
            'DATABASE_URL' => 'postgresql://u193_s163:old-sidecar-password@db:5432/s163_db',
        ], 'postgresql');

        $this->assertTrue($result['corrected']);
        $this->assertTrue($result['password_aligned']);
        $this->assertSame('panel-password', $result['env']['DB_PASSWORD']);
        $this->assertSame('panel-password', $result['env']['POSTGRES_PASSWORD']);
        $this->assertStringContainsString('panel-password', urldecode($result['env']['DATABASE_URL']));
        $this->assertStringNotContainsString('old-sidecar-password', urldecode($result['env']['DATABASE_URL']));
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
