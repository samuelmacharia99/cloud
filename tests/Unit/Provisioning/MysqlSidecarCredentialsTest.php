<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MysqlSidecarCredentialsTest extends TestCase
{
    #[Test]
    public function privilege_repair_sql_drops_shadow_hosts_and_recreates_percent_and_localhost_only(): void
    {
        $sql = app(ContainerDeploymentService::class)->mysqlPrivilegeRepairSql(
            's24_db',
            'u74_s24',
            'secret',
            ['10.201.0.26'],
        );

        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `s24_db`', $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'10.201.0.26'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'10.%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'172.%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'localhost'", $sql);
        $this->assertStringContainsString("CREATE USER 'u74_s24'@'%' IDENTIFIED WITH mysql_native_password BY 'secret'", $sql);
        $this->assertStringContainsString("CREATE USER 'u74_s24'@'localhost' IDENTIFIED WITH mysql_native_password BY 'secret'", $sql);
        $this->assertStringContainsString("ALTER USER 'u74_s24'@'%' IDENTIFIED WITH mysql_native_password BY 'secret'", $sql);
        $this->assertStringContainsString("ALTER USER 'u74_s24'@'%' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'%'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'localhost'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS ''@'%'", $sql);
        $this->assertStringContainsString('FLUSH PRIVILEGES', $sql);

        $this->assertStringNotContainsString('CREATE USER IF NOT EXISTS', $sql);
        $this->assertStringNotContainsString("CREATE USER 'u74_s24'@'10.201.0.26'", $sql);
        $this->assertStringNotContainsString("CREATE USER 'u74_s24'@'10.%'", $sql);
        $this->assertStringNotContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'10.201.0.26'", $sql);
    }

    #[Test]
    public function grant_sql_warnings_must_not_take_the_mysql_sidecar_down(): void
    {
        $service = app(ContainerDeploymentService::class);

        $this->assertFalse($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1396 (HY000) at line 1: Operation DROP USER failed for 'u74_s24'@'%'"
        ));
        $this->assertFalse($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1396 (HY000): Operation CREATE USER failed for 'u74_s24'@'%'"
        ));
        $this->assertTrue($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1045 (28000): Access denied for user 'root'@'localhost' (using password: YES)"
        ));
        $this->assertFalse($service->mysqlSidecarGrantShouldStopDatabase(
            "ERROR 1524 (HY000): Plugin 'mysql_native_password' is not loaded"
        ));
    }

    #[Test]
    public function shadow_host_drop_sql_targets_every_account_except_percent_and_localhost(): void
    {
        $sql = app(ContainerDeploymentService::class)->mysqlDropShadowHostsSql('u74_s24', [
            '%',
            'localhost',
            '10.%',
            '172.%',
            '10.201.0.11',
            '127.0.0.1',
        ]);

        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'10.%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'172.%'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'10.201.0.11'", $sql);
        $this->assertStringContainsString("DROP USER IF EXISTS 'u74_s24'@'127.0.0.1'", $sql);
        $this->assertStringContainsString('FLUSH PRIVILEGES', $sql);
        $this->assertStringNotContainsString("DROP USER IF EXISTS 'u74_s24'@'%'", $sql);
        $this->assertStringNotContainsString("DROP USER IF EXISTS 'u74_s24'@'localhost'", $sql);
        $this->assertStringNotContainsString('| mysql', $sql);
    }

    #[Test]
    public function compose_restart_recreates_the_app_service_not_the_database_sidecar(): void
    {
        $command = app(ContainerDeploymentService::class)->composeRestartAppCommand(
            '/opt/talksasa/containers/user-74-service-24-laravel',
            'user-74-service-24-laravel'
        );

        $this->assertStringContainsString('docker compose -f docker-compose.yml up -d --no-deps --pull never --force-recreate', $command);
        $this->assertStringContainsString('user-74-service-24-laravel', $command);
        $this->assertStringNotContainsString(' restart ', $command);
        $this->assertDoesNotMatchRegularExpression('/up -d["\']?\s*$/', $command);
    }

    #[Test]
    public function compose_logs_window_skips_stale_mysql_init(): void
    {
        $command = app(ContainerDeploymentService::class)->composeLogsCommand(
            '/opt/talksasa/containers/user-74-service-24-laravel',
            200
        );

        $this->assertStringContainsString('--since 6h', $command);
        $this->assertStringContainsString('--tail=200', $command);
    }

    #[Test]
    public function compose_environment_patch_sets_cookie_session_on_the_app_service(): void
    {
        $yaml = <<<'YAML'
services:
  user-74-service-24-laravel:
    container_name: user-74-service-24-laravel
    environment:
      SESSION_DRIVER: database
      CACHE_STORE: database
      DB_DATABASE: s24_db
  db:
    image: mysql:8.0
YAML;

        $patched = app(ContainerDeploymentService::class)->patchComposeServiceEnvironment(
            $yaml,
            'user-74-service-24-laravel',
            [
                'SESSION_DRIVER' => 'cookie',
                'CACHE_STORE' => 'file',
                'CACHE_DRIVER' => 'file',
                'DB_PASSWORD' => 'grant-password',
                'DATABASE_URL' => 'mysql://u74_s24:grant-password@db:3306/s24_db',
            ]
        );

        $this->assertStringContainsString('SESSION_DRIVER: cookie', $patched);
        $this->assertStringContainsString('CACHE_STORE: file', $patched);
        $this->assertStringContainsString('CACHE_DRIVER: file', $patched);
        $this->assertStringNotContainsString('SESSION_DRIVER: database', $patched);
        $this->assertStringContainsString('DB_DATABASE: s24_db', $patched);
        $this->assertStringContainsString('DB_PASSWORD: grant-password', $patched);
        $this->assertMatchesRegularExpression("/DATABASE_URL: '?mysql:\\/\\/u74_s24:grant-password@db:3306\\/s24_db'?/", $patched);
    }

    #[Test]
    public function compose_environment_list_form_is_patched_in_place(): void
    {
        $yaml = <<<'YAML'
services:
  user-74-service-24-laravel:
    environment:
      - SESSION_DRIVER=database
      - DB_HOST=db
YAML;

        $patched = app(ContainerDeploymentService::class)->patchComposeServiceEnvironment(
            $yaml,
            'user-74-service-24-laravel',
            ['SESSION_DRIVER' => 'cookie']
        );

        $this->assertStringContainsString('SESSION_DRIVER=cookie', $patched);
        $this->assertStringNotContainsString('SESSION_DRIVER=database', $patched);
        $this->assertStringContainsString('DB_HOST=db', $patched);
    }

    #[Test]
    public function pdo_probe_script_does_not_interpolate_dollar_signs_in_passwords(): void
    {
        $script = app(ContainerDeploymentService::class)->phpPdoEvalScript(
            'mysql:host=db;port=3306;dbname=s24_db',
            'u74_s24',
            'p$ass"word\'x',
            '$pdo->query("SELECT 1"); fwrite(STDOUT, "ok"); exit(0);'
        );

        $this->assertStringContainsString('base64_decode', $script);
        $this->assertStringNotContainsString('p$ass', $script);
        $this->assertMatchesRegularExpression('/base64_decode\("[A-Za-z0-9+\/=]+"\)/', $script);
        preg_match('/base64_decode\("([A-Za-z0-9+\/=]+)"\)/', $script, $matches);
        $decoded = json_decode(base64_decode($matches[1]), true);
        $this->assertSame('p$ass"word\'x', $decoded['pass']);
        $this->assertSame('u74_s24', $decoded['user']);
        $this->assertSame('mysql:host=db;port=3306;dbname=s24_db', $decoded['dsn']);
    }

    #[Test]
    public function leftover_shadow_hosts_get_the_known_password_when_drop_fails(): void
    {
        $sql = app(ContainerDeploymentService::class)->mysqlAlignHostPasswordsSql(
            's24_db',
            'u74_s24',
            'secret',
            ['10.%', '%', 'localhost']
        );

        $this->assertStringContainsString("ALTER USER 'u74_s24'@'10.%' IDENTIFIED BY 'secret'", $sql);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON `s24_db`.* TO 'u74_s24'@'10.%'", $sql);
        $this->assertStringNotContainsString("ALTER USER 'u74_s24'@'%'", $sql);
        $this->assertSame('KILL 12; KILL 15;', app(ContainerDeploymentService::class)->mysqlKillIdsSql([12, '15', 0, 'x']));
    }

    #[Test]
    public function shared_network_db_alias_is_replaced_with_unique_sidecar_dns_name(): void
    {
        $service = app(ContainerDeploymentService::class);

        $this->assertSame(
            'user-74-service-24-laravel-db',
            $service->applicationDatabaseHost(['DB_HOST' => 'db'], 'user-74-service-24-laravel')
        );
        $this->assertTrue($service->isAmbiguousSharedNetworkDatabaseHost('db'));
        $this->assertFalse($service->isAmbiguousSharedNetworkDatabaseHost('user-74-service-24-laravel-db'));

        $pinned = $service->pinApplicationDatabaseHost([
            'DB_USERNAME' => 'u74_s24',
            'DB_PASSWORD' => 'secret',
            'DB_DATABASE' => 's24_db',
            'DB_HOST' => 'db',
        ], 'user-74-service-24-laravel', 'mysql');

        $this->assertSame('user-74-service-24-laravel-db', $pinned['DB_HOST']);
        $this->assertStringContainsString('@user-74-service-24-laravel-db:3306/', $pinned['DATABASE_URL']);
        $this->assertStringNotContainsString('@db:', $pinned['DATABASE_URL']);
    }

    #[Test]
    public function compose_exec_stays_on_the_db_service_when_app_dns_host_is_unique(): void
    {
        $this->assertSame('db', app(ContainerDeploymentService::class)->resolveMysqlComposeServiceName([
            'DB_HOST' => 'user-74-service-24-laravel-db',
            'DB_DATABASE' => 's24_db',
        ]));
        $this->assertSame('mysql', app(ContainerDeploymentService::class)->resolveMysqlComposeServiceName([
            'WORDPRESS_DB_NAME' => 'wordpress',
            'DB_HOST' => 'user-74-service-24-wordpress-db',
        ]));
    }

    #[Test]
    public function restart_compose_overrides_replace_shared_db_alias(): void
    {
        $deployment = new ContainerDeployment([
            'container_name' => 'user-74-service-24-laravel',
            'env_values' => [
                'DB_HOST' => 'db',
                'DB_USERNAME' => 'u74_s24',
                'DB_PASSWORD' => 'secret',
                'DB_DATABASE' => 's24_db',
                'SESSION_DRIVER' => 'database',
                'DATABASE_URL' => 'mysql://u74_s24:secret@db:3306/s24_db',
            ],
        ]);

        $overrides = app(ContainerDeploymentService::class)->composeRuntimeEnvironmentOverrides($deployment);

        $this->assertSame('cookie', $overrides['SESSION_DRIVER']);
        $this->assertSame('file', $overrides['CACHE_STORE']);
        $this->assertSame('user-74-service-24-laravel-db', $overrides['DB_HOST']);
        $this->assertStringContainsString('@user-74-service-24-laravel-db:3306/', $overrides['DATABASE_URL']);
        $this->assertStringNotContainsString('@db:', $overrides['DATABASE_URL']);

        $yaml = <<<'YAML'
services:
  user-74-service-24-laravel:
    container_name: user-74-service-24-laravel
    environment:
      DB_HOST: db
      DATABASE_URL: mysql://u74_s24:secret@db:3306/s24_db
  db:
    image: mysql:8.0
YAML;

        $patched = app(ContainerDeploymentService::class)->patchComposeServiceEnvironment(
            $yaml,
            'user-74-service-24-laravel',
            $overrides
        );

        $this->assertStringContainsString('DB_HOST: user-74-service-24-laravel-db', $patched);
        $this->assertStringNotContainsString("DB_HOST: db\n", $patched);
        $this->assertStringNotContainsString('@db:', $patched);
    }
}
