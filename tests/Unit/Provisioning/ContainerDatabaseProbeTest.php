<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerDoctorService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerDatabaseProbeTest extends TestCase
{
    #[Test]
    public function nodejs_probe_does_not_use_www_data_or_php(): void
    {
        $service = app(ContainerDeploymentService::class);
        $env = [
            'DB_HOST' => 'user-231-service-351-nodejs-db',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 's351_db',
            'DB_USERNAME' => 'u231_s351',
            'DB_PASSWORD' => 'p$ecret',
        ];

        $this->assertFalse($service->applicationDatabaseProbeUsesPhp('nodejs'));
        $this->assertSame('nodejs', $service->inferTemplateSlugFromContainerName('user-231-service-351-nodejs'));

        $client = $service->nodeDatabaseClientProbeCommand(
            'user-231-service-351-nodejs',
            'postgresql',
            $env
        );
        $tcp = $service->databaseTcpProbeCommand(
            'user-231-service-351-nodejs',
            $env,
            'postgresql'
        );
        $sidecar = $service->sidecarApplicationCredentialProbeCommand(
            '/opt/talksasa/containers/user-231-service-351-nodejs',
            'postgresql',
            $env
        );

        $this->assertStringContainsString('docker exec ', $client);
        $this->assertStringContainsString(' node -e ', $client);
        $this->assertStringNotContainsString('www-data', $client);
        $this->assertStringNotContainsString('php -r', $client);
        $this->assertStringNotContainsString('p$ecret', $client);

        $this->assertStringContainsString('user-231-service-351-nodejs-db', $tcp);
        $this->assertStringNotContainsString('www-data', $tcp);

        $this->assertStringContainsString('psql -h 127.0.0.1', $sidecar);
        $this->assertStringContainsString('-d \'s351_db\'', $sidecar);
        $this->assertStringNotContainsString('mysql', $sidecar);
    }

    #[Test]
    public function laravel_probe_still_uses_www_data_php(): void
    {
        $service = app(ContainerDeploymentService::class);
        $script = $service->phpPdoEvalScript(
            'pgsql:host=db;port=5432;dbname=s24_db',
            'u74_s24',
            'secret',
            'fwrite(STDOUT,"ok"); exit(0);'
        );

        $command = $service->phpDatabaseProbeCommand('user-74-service-24-laravel', $script, 'laravel');

        $this->assertTrue($service->applicationDatabaseProbeUsesPhp('laravel'));
        $this->assertStringContainsString("-u 'www-data'", $command);
        $this->assertStringContainsString(' php -r ', $command);
    }

    #[Test]
    public function missing_www_data_is_treated_as_wrong_runtime_not_auth_failure(): void
    {
        $service = app(ContainerDeploymentService::class);

        $this->assertTrue($service->isMissingPhpRuntimeProbeError(
            'Error response from daemon: unable to find user www-data: no matching entries in passwd file'
        ));
        $this->assertTrue($service->isMissingPhpRuntimeProbeError(
            'OCI runtime exec failed: exec: "php": executable file not found in $PATH'
        ));
        $this->assertFalse($service->isMissingPhpRuntimeProbeError(
            'password authentication failed for user "u231_s351"'
        ));
    }

    #[Test]
    public function postgres_readiness_names_a_real_database(): void
    {
        $command = app(ContainerDeploymentService::class)->postgresqlSidecarReadinessCommand([
            'POSTGRES_USER' => 'u231_s351',
            'POSTGRES_DB' => 's351_db',
            'DB_PASSWORD' => 'secret',
        ]);

        $this->assertStringContainsString('pg_isready', $command);
        $this->assertStringContainsString("-U 'u231_s351'", $command);
        $this->assertStringContainsString('-d ', $command);
        $this->assertStringNotContainsString('pg_isready -U \'u231_s351\' -h localhost', $command);
    }

    #[Test]
    public function postgres_admin_candidates_prefer_custom_superuser_over_postgres_role(): void
    {
        $service = app(ContainerDeploymentService::class);
        $env = [
            'POSTGRES_USER' => 'u231_s351',
            'DB_USERNAME' => 'u231_s351',
            'POSTGRES_DB' => 's351_db',
            'DB_DATABASE' => 's351_db',
        ];

        $roles = $service->postgresqlAdminRoleCandidates($env, 'u231_s351');
        $databases = $service->postgresqlAdminDatabaseCandidates($env);

        $this->assertSame('u231_s351', $roles[0]);
        $this->assertContains('postgres', $roles);
        $this->assertSame('postgres', $databases[0]);
        $this->assertContains('s351_db', $databases);
    }

    #[Test]
    public function postgres_repair_failure_does_not_mention_mysql_hosts_or_1045(): void
    {
        $message = app(ContainerDoctorService::class)->databaseRepairLiveFailureMessage(
            'postgresql',
            'Database "s351_db" credentials synced and .env rewritten (including DATABASE_URL).',
            'Error response from daemon: unable to find user www-data: no matching entries in passwd file',
            'user-231-service-351-nodejs-db',
            ' mysql.user Host values: should-not-appear.'
        );

        $this->assertStringContainsString('s351_db', $message);
        $this->assertStringContainsString('user-231-service-351-nodejs-db', $message);
        $this->assertStringContainsString('POSTGRES_USER', $message);
        $this->assertStringNotContainsString('mysql.user', $message);
        $this->assertStringNotContainsString('1045', $message);
        $this->assertStringNotContainsString('user@overlay-ip', $message);
        $this->assertStringNotContainsString('user@%', $message);
    }

    #[Test]
    public function node_postgres_repair_steps_do_not_mention_php_fpm_or_1045(): void
    {
        $steps = app(ContainerDoctorService::class)->repairDatabaseCredentialsManualSteps('nodejs', 'postgresql');

        $this->assertNotEmpty($steps);
        $joined = implode(' ', $steps);
        $this->assertStringContainsString('compose DB_*', $joined);
        $this->assertStringNotContainsString('PHP-FPM', $joined);
        $this->assertStringNotContainsString('1045', $joined);
        $this->assertStringContainsString('Do not Reset database', $joined);
    }

    #[Test]
    public function compose_overrides_keep_postgresql_url_when_connection_is_pgsql(): void
    {
        $deployment = new ContainerDeployment([
            'container_name' => 'user-231-service-351-nodejs',
            'env_values' => [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'db',
                'DB_USERNAME' => 'u231_s351',
                'DB_PASSWORD' => 'secret',
                'DB_DATABASE' => 's351_db',
                'POSTGRES_USER' => 'u231_s351',
                'POSTGRES_DB' => 's351_db',
                'DATABASE_URL' => 'postgresql://u231_s351:secret@db:5432/s351_db',
            ],
        ]);

        $overrides = app(ContainerDeploymentService::class)->composeRuntimeEnvironmentOverrides($deployment);

        $this->assertSame('user-231-service-351-nodejs-db', $overrides['DB_HOST']);
        $this->assertStringContainsString('postgresql://', $overrides['DATABASE_URL']);
        $this->assertStringContainsString('@user-231-service-351-nodejs-db:5432/s351_db', $overrides['DATABASE_URL']);
        $this->assertStringNotContainsString('mysql://', $overrides['DATABASE_URL']);
    }

    #[Test]
    public function wordpress_probe_uses_mysqli_and_the_mysql_sidecar_host(): void
    {
        $service = app(ContainerDeploymentService::class);
        $env = [
            'DB_HOST' => 'db',
            'WORDPRESS_DB_HOST' => 'mysql:3306',
            'WORDPRESS_DB_NAME' => 'wordpress',
            'WORDPRESS_DB_USER' => 'wordpress',
            'WORDPRESS_DB_PASSWORD' => 'secret',
        ];

        $this->assertSame('mysql', $service->applicationDatabaseHost($env));
        $this->assertSame(
            'user-488-service-373-wordpress-mysql',
            $service->applicationDatabaseHost($env, 'user-488-service-373-wordpress')
        );
        $this->assertSame(
            'user-488-service-373-wordpress-mysql',
            $service->sidecarDnsHost('user-488-service-373-wordpress')
        );
        $this->assertSame(
            'user-488-service-373-wordpress-mysql',
            $service->applicationDatabaseHost(['WORDPRESS_DB_HOST' => 'localhost'], 'user-488-service-373-wordpress')
        );
        $this->assertSame('mysql', $service->defaultMysqlSidecarHost($env));
        $this->assertSame('db', $service->defaultMysqlSidecarHost(['DB_DATABASE' => 'appdb']));

        $script = $service->phpWordpressMysqliEvalScript('mysql', 3306, 'wordpress', 'wordpress', 'secret');
        $this->assertStringContainsString('mysqli', $script);
        $this->assertStringNotContainsString('new PDO', $script);
        $this->assertStringNotContainsString('secret', $script);

        $identity = $service->applicationDatabaseCredentials($env, 'mysql');
        $this->assertSame('wordpress', $identity['database']);
        $this->assertSame('wordpress', $identity['username']);
        $this->assertSame('secret', $identity['password']);

        $split = $service->splitDatabaseHostAndPort('mysql:3306', '3306');
        $this->assertSame(['host' => 'mysql', 'port' => '3306'], $split);
    }

    #[Test]
    public function the_tcp_probe_does_not_assume_the_container_has_node(): void
    {
        // A Python image has no node, so the old probe died with
        // "executable file not found" and the caller reported that as the
        // customer's application being unable to reach its own database.
        $command = app(ContainerDeploymentService::class)->databaseTcpProbeCommand(
            'user-493-service-457-python',
            ['DB_HOST' => 'user-493-service-457-python-db', 'DB_PORT' => '5432'],
            'postgresql',
        );

        $this->assertNotNull($command);
        $this->assertStringContainsString('command -v python3', $command);
        $this->assertStringContainsString('command -v ruby', $command);
        $this->assertStringContainsString('command -v node', $command);
        $this->assertStringContainsString('user-493-service-457-python-db', $command);
        $this->assertStringNotContainsString('docker exec \'user-493-service-457-python\' node ', $command);
    }

    #[Test]
    public function a_container_with_no_interpreter_says_so_instead_of_guessing(): void
    {
        $command = app(ContainerDeploymentService::class)->databaseTcpProbeCommand(
            'user-1-service-2-go',
            ['DB_HOST' => 'user-1-service-2-go-db', 'DB_PORT' => '5432'],
            'postgresql',
        );

        // The exit status the caller reads as "the platform could not test",
        // which is a different sentence from "your app cannot reach its
        // database" and the whole reason this distinction exists.
        $this->assertStringContainsString('exit '.ContainerDeploymentService::PROBE_UNAVAILABLE_EXIT, $command);
        $this->assertStringContainsString('probe_unavailable', $command);
    }

    #[Test]
    public function a_host_name_that_is_really_a_shell_command_is_refused(): void
    {
        // The whole probe is one escapeshellarg'd sh -c string, and DB_HOST is
        // customer-editable. The node form escaped this by accident through
        // json_encode; nc and /dev/tcp would not have.
        $service = app(ContainerDeploymentService::class);

        $this->assertTrue($service->databaseProbeHostIsSafe('user-1-service-2-python-db'));
        $this->assertFalse($service->databaseProbeHostIsSafe('db; rm -rf /'));
        $this->assertFalse($service->databaseProbeHostIsSafe('db$(id)'));
        $this->assertFalse($service->databaseProbeHostIsSafe('db`id`'));

        $this->assertNull($service->databaseTcpProbeCommand(
            'user-1-service-2-python',
            ['DB_HOST' => 'db; rm -rf /', 'DB_PORT' => '5432'],
            'postgresql',
        ));
    }

    #[Test]
    public function the_probe_never_carries_the_password(): void
    {
        $command = app(ContainerDeploymentService::class)->databaseTcpProbeCommand(
            'user-1-service-2-python',
            [
                'DB_HOST' => 'user-1-service-2-python-db',
                'DB_PORT' => '5432',
                'DB_PASSWORD' => 'p$ecret',
            ],
            'postgresql',
        );

        $this->assertStringNotContainsString('p$ecret', (string) $command);
    }
}
