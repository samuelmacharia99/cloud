<?php

namespace Tests\Unit\Provisioning;

use App\Models\DatabaseTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DirectAdminToContainerMigrationDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function stack_may_export_database_includes_nodejs(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $this->assertTrue($service->stackMayExportDatabase('nodejs'));
        $this->assertTrue($service->stackMayExportDatabase('laravel'));
        $this->assertFalse($service->stackMayExportDatabase('static_or_php'));
    }

    #[Test]
    public function merge_database_url_populates_credentials(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $creds = [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ];

        $migrator->mergeDatabaseUrlIntoCredentials(
            $creds,
            'mysql://appuser:secret@127.0.0.1:3306/sigtuna_app'
        );

        $this->assertSame('127.0.0.1', $creds['DB_HOST']);
        $this->assertSame('appuser', $creds['DB_USER']);
        $this->assertSame('secret', $creds['DB_PASSWORD']);
        $this->assertSame('sigtuna_app', $creds['DB_NAME']);
    }

    #[Test]
    public function ensure_mysql_sidecar_for_import_sets_database_id_on_service(): void
    {
        $template = DatabaseTemplate::query()->create([
            'name' => 'Container MySQL '.uniqid(),
            'slug' => 'container-mysql-'.uniqid(),
            'type' => 'mysql',
            'hosting_type' => 'container',
            'default_port' => 3306,
            'is_active' => true,
            'order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'App Hosting',
            'slug' => 'app-hosting-'.uniqid(),
            'type' => 'container_hosting',
            'monthly_price' => 1000,
            'is_active' => true,
            'provisioning_driver_key' => 'container',
        ]);

        $service = Service::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'name' => 'sigtuna.org',
            'status' => 'provisioning',
            'billing_cycle' => 'annual',
            'provisioning_driver_key' => 'container',
            'service_meta' => ['domain' => 'sigtuna.org'],
        ]);

        app(DirectAdminToContainerMigrationService::class)->ensureMysqlSidecarForImport($service->fresh());

        $service->refresh();
        $this->assertSame($template->id, (int) ($service->service_meta['database_id'] ?? 0));
    }

    #[Test]
    public function should_dump_database_for_nodejs_only_when_env_or_operator_selected(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $this->assertFalse($migrator->shouldDumpDatabaseForExport('nodejs', [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ], null));

        $this->assertTrue($migrator->shouldDumpDatabaseForExport('nodejs', [
            'DB_NAME' => 'sigtunaco_app',
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ], null));

        $this->assertTrue($migrator->shouldDumpDatabaseForExport('nodejs', [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ], 'sigtunaco_db1'));
    }

    #[Test]
    public function parse_directadmin_mysql_conf_lines_reads_user_and_passwd(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $parsed = $migrator->parseDirectAdminMysqlConfLines(
            "user=sigtunaco_db1\npasswd=secret\nhost=localhost\n"
        );

        $this->assertSame('sigtunaco_db1', $parsed['DB_USER']);
        $this->assertSame('secret', $parsed['DB_PASSWORD']);
        $this->assertSame('localhost', $parsed['DB_HOST']);
    }

    #[Test]
    public function enrich_database_credentials_uses_directadmin_user_mysql_conf(): void
    {
        $ssh = \Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')
            ->andReturn(
                "user=sigtunaco_db1\npasswd=from-da\nhost=localhost\n",
                '',
            );

        $migrator = app(DirectAdminToContainerMigrationService::class);
        $enriched = $migrator->enrichDatabaseCredentialsFromDirectAdmin(
            $ssh,
            'sigtunaco',
            'sigtunaco_db1',
            [
                'DB_NAME' => 'sigtunaco_db1',
                'DB_USER' => null,
                'DB_PASSWORD' => null,
                'DB_HOST' => 'localhost',
            ],
        );

        $this->assertSame('sigtunaco_db1', $enriched['DB_USER']);
        $this->assertSame('from-da', $enriched['DB_PASSWORD']);
    }

    #[Test]
    public function compose_mysql_wait_uses_app_user_when_root_password_is_missing(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $this->assertSame(
            ['user' => 'root', 'password' => 'root-secret'],
            $migrator->composeMysqlWaitCredentials([
                'user' => 'appuser',
                'password' => 'app-secret',
                'root_password' => 'root-secret',
            ])
        );
        $this->assertSame(
            ['user' => 'appuser', 'password' => 'app-secret'],
            $migrator->composeMysqlWaitCredentials([
                'user' => 'appuser',
                'password' => 'app-secret',
                'root_password' => '',
            ])
        );
    }

    #[Test]
    public function compose_mysql_up_creates_the_sidecar_instead_of_starting_a_missing_container(): void
    {
        $cmd = app(DirectAdminToContainerMigrationService::class)
            ->composeMysqlUpCommand('/opt/talksasa/containers/site-1', 'mysql');

        $this->assertStringContainsString('docker compose up -d --no-deps', $cmd);
        $this->assertStringContainsString('mysql', $cmd);
        $this->assertStringNotContainsString('docker compose start', $cmd);
    }

    #[Test]
    public function compose_mysql_probe_accepts_mariadb_admin_on_official_images(): void
    {
        $cmd = app(DirectAdminToContainerMigrationService::class)
            ->composeMysqlProbeCommand('/opt/talksasa/containers/site-1', 'mysql', 'root', 'secret');

        $this->assertStringContainsString('mysqladmin ping', $cmd);
        $this->assertStringContainsString('mariadb-admin ping', $cmd);
        $this->assertStringContainsString('MYSQL_PWD=', $cmd);
    }

    #[Test]
    public function compose_mysql_needs_start_detects_a_missing_container(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $this->assertTrue($migrator->composeMysqlNeedsStart('service "mysql" is not running'));
        $this->assertTrue($migrator->composeMysqlNeedsStart('Error: No container found'));
        $this->assertTrue($migrator->composeMysqlNeedsStart('Container is restarting, wait until the container is running'));
        $this->assertFalse($migrator->composeMysqlNeedsStart('Access denied for user'));
    }

    #[Test]
    public function compose_mysql_detects_a_half_initialized_datadir_and_rebuilds_only_that_volume(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $this->assertTrue($migrator->composeMysqlDatadirIsUnusable(
            '--initialize specified but the data directory has files in it. Aborting.'
        ));
        $this->assertTrue($migrator->composeMysqlDatadirIsUnusable(
            'The designated data directory /var/lib/mysql/ is unusable.'
        ));
        $this->assertFalse($migrator->composeMysqlDatadirIsUnusable('Access denied for user'));

        $cmd = $migrator->composeMysqlResetUnusableDatadirCommand(
            '/opt/talksasa/containers/user-485-service-370-laravel',
            'db'
        );
        $this->assertStringContainsString('docker compose rm -f', $cmd);
        $this->assertStringContainsString('user-485-service-370-laravel', $cmd);
        $this->assertStringContainsString('db_data|mysql_data', $cmd);
        $this->assertStringContainsString('docker volume rm', $cmd);
        $this->assertStringNotContainsString('compose down', $cmd);
    }

    #[Test]
    public function wait_for_compose_mysql_resets_unusable_datadir_only_when_requested(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $path = '/opt/talksasa/containers/user-485-service-370-laravel';
        $unusable = 'Restarting --initialize specified but the data directory has files in it. Aborting.';

        $ssh = \Mockery::mock(SSHService::class);
        $resetSeen = false;
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $cmd) use ($unusable, &$resetSeen) {
            if (str_contains($cmd, 'docker volume rm')) {
                $resetSeen = true;

                return '';
            }
            if (str_contains($cmd, 'mysqladmin ping') || str_contains($cmd, 'mariadb-admin ping')) {
                if ($resetSeen) {
                    return '';
                }

                throw new \RuntimeException('Container is restarting, wait until the container is running');
            }
            if (str_contains($cmd, 'docker compose logs') || str_contains($cmd, 'docker compose ps')) {
                return $unusable;
            }

            return '';
        });

        $migrator->waitForComposeMysql($ssh, $path, 'db', 'secret', 15, 'root', true);
        $this->assertTrue($resetSeen);
    }

    #[Test]
    public function wait_for_compose_mysql_does_not_wipe_datadir_during_backup_restore(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $path = '/opt/talksasa/containers/user-1-service-1-laravel';

        $ssh = \Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $cmd) {
            if (str_contains($cmd, 'docker volume rm')) {
                throw new \RuntimeException('must not wipe live datadir');
            }
            if (str_contains($cmd, 'mysqladmin ping') || str_contains($cmd, 'mariadb-admin ping')) {
                throw new \RuntimeException('Container is restarting, wait until the container is running');
            }
            if (str_contains($cmd, 'docker compose logs')) {
                return '--initialize specified but the data directory has files in it.';
            }

            return '';
        });

        try {
            $migrator->waitForComposeMysql($ssh, $path, 'db', 'secret', 5, 'root', false);
            $this->fail('expected wait to time out');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not become ready', $e->getMessage());
            $this->assertStringNotContainsString('must not wipe', $e->getMessage());
        }
    }

    #[Test]
    public function compose_mysql_client_falls_back_to_mariadb_on_official_images(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);

        $shell = $migrator->composeMysqlClientShell('wordpress', 'wordpress', 'SELECT 1');
        $this->assertStringContainsString('command -v mysql', $shell);
        $this->assertStringContainsString('command -v mariadb', $shell);
        $this->assertStringContainsString("mysql -uwordpress 'wordpress' -e 'SELECT 1'", $shell);
        $this->assertStringContainsString("mariadb -uwordpress 'wordpress' -e 'SELECT 1'", $shell);

        $exec = $migrator->composeMysqlExecCommand(
            '/opt/talksasa/containers/site-1',
            'mysql',
            'root',
            'secret',
            'CREATE DATABASE IF NOT EXISTS `appdb`;',
        );
        $this->assertStringContainsString('docker compose exec -T', $exec);
        $this->assertStringContainsString("MYSQL_PWD='secret'", $exec);
        $this->assertStringContainsString('sh -c', $exec);
        $this->assertStringContainsString('mariadb', $exec);
    }
}
