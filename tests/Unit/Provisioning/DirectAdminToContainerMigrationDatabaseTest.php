<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\DatabaseTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDoctorService;
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
        $this->assertTrue($service->stackMayExportDatabase('static_or_php'));
        $this->assertTrue($service->stackImportsDatabaseDump('static_or_php'));
        $this->assertFalse($service->stackMayExportDatabase('unknown'));
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

        $this->assertTrue($migrator->shouldDumpDatabaseForExport('static_or_php', [
            'DB_NAME' => 'roadtrip_db',
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ], null));

        $this->assertFalse($migrator->shouldDumpDatabaseForExport('static_or_php', [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ], null));
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
        $this->assertTrue($migrator->composeMysqlShouldResetDatadir(
            'Container is restarting, wait until the container is running'
        ));
        $this->assertFalse($migrator->composeMysqlShouldResetDatadir('Access denied for user'));

        $cmd = $migrator->composeMysqlResetUnusableDatadirCommand(
            '/opt/talksasa/containers/user-485-service-370-laravel',
            'db'
        );
        $this->assertStringContainsString('docker inspect -f', $cmd);
        $this->assertStringContainsString('/var/lib/mysql', $cmd);
        $this->assertStringContainsString('docker rm -f', $cmd);
        $this->assertStringContainsString('${project}_db_data', $cmd);
        $this->assertStringContainsString('${project}_mysql_data', $cmd);
        $this->assertStringContainsString('docker volume rm -f', $cmd);
        $this->assertStringContainsString('--force-recreate', $cmd);
        $this->assertStringContainsString('user-485-service-370-laravel', $cmd);
        $this->assertStringNotContainsString('compose down', $cmd);
    }

    #[Test]
    public function reset_compose_mysql_datadir_for_import_runs_the_volume_wipe(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $path = '/opt/talksasa/containers/user-486-service-371-wordpress';
        $ssh = \Mockery::mock(SSHService::class);
        $wiped = false;
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(function (string $cmd) use ($path) {
                return str_contains($cmd, $path)
                    && str_contains($cmd, 'docker volume rm -f')
                    && str_contains($cmd, '--force-recreate');
            })
            ->andReturnUsing(function () use (&$wiped) {
                $wiped = true;

                return '';
            });

        $migrator->resetComposeMysqlDatadirForImport($ssh, $path, 'mysql');
        $this->assertTrue($wiped);
    }

    #[Test]
    public function wait_for_compose_mysql_resets_a_restarting_sidecar_before_import(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $path = '/opt/talksasa/containers/user-485-service-370-laravel';

        $ssh = \Mockery::mock(SSHService::class);
        $resetSeen = false;
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$resetSeen) {
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
                return 'Restarting (1) 54 seconds ago';
            }

            return '';
        });

        $migrator->waitForComposeMysql($ssh, $path, 'db', 'secret', 15, 'root', true);
        $this->assertTrue($resetSeen);
    }

    #[Test]
    public function wait_for_compose_mysql_resets_when_probe_says_restarting_even_without_logs(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $path = '/opt/talksasa/containers/user-485-service-370-laravel';

        $ssh = \Mockery::mock(SSHService::class);
        $resetSeen = false;
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $cmd) use (&$resetSeen) {
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
        $this->assertStringNotContainsString('--binary-mode', $shell);

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

    #[Test]
    public function dump_import_uses_binary_mode_so_backslashes_are_not_client_commands(): void
    {
        $shell = app(DirectAdminToContainerMigrationService::class)
            ->composeMysqlClientShell('root', 's426_db', null, true);

        $this->assertStringContainsString('mysql -uroot --binary-mode --default-character-set=utf8mb4 --max-allowed-packet=1073741824', $shell);
        $this->assertStringContainsString('mariadb -uroot --binary-mode --default-character-set=utf8mb4 --max-allowed-packet=1073741824', $shell);
    }

    #[Test]
    public function inventory_from_directadmin_legacy_reads_converted_service_meta(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $service = new Service;
        $service->provisioning_driver_key = 'container';
        $service->service_meta = [
            'da_legacy' => [
                'username' => 'u483',
                'da_node_id' => 12,
                'domain' => 'roadtrip.example.com',
                'stack' => 'static_or_php',
                'docroot' => '/home/u483/domains/roadtrip.example.com/public_html',
                'databases' => [['name' => 'roadtrip_db'], 'also_string'],
            ],
        ];

        $inventory = $migrator->inventoryFromDirectAdminLegacy($service);

        $this->assertSame('u483', $inventory['username']);
        $this->assertSame(12, $inventory['da_node_id']);
        $this->assertSame('static_or_php', $inventory['stack']);
        $this->assertSame(
            [['name' => 'roadtrip_db'], ['name' => 'also_string']],
            $inventory['databases']
        );
        $this->assertTrue($migrator->canRepullDirectAdminDatabase($service));
        $this->assertSame('u483', $migrator->directAdminUsername($service));
    }

    #[Test]
    public function cannot_repull_directadmin_database_without_legacy_node(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $service = new Service;
        $service->provisioning_driver_key = 'container';
        $service->service_meta = ['da_legacy' => ['username' => 'u483']];

        $this->assertNull($migrator->inventoryFromDirectAdminLegacy($service));
        $this->assertFalse($migrator->canRepullDirectAdminDatabase($service));
    }

    #[Test]
    public function import_dump_refuses_when_sidecar_already_has_tables(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = Node::factory()->create(['type' => 'container_host']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'docker_compose_content' => "services:\n  app:\n    image: php\n  db:\n    image: mysql:8.0\n",
        ]);

        $dump = tempnam(sys_get_temp_dir(), 'da-dump');
        file_put_contents($dump, "-- dump\n");

        try {
            app(DirectAdminToContainerMigrationService::class)->importDatabaseDumpIntoSidecar(
                $service->fresh(['containerDeployment.node']),
                $dump,
                null,
                12
            );
            $this->fail('expected refuse when tables exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has 12 tables', $e->getMessage());
            $this->assertStringContainsString('Do not Reset database', $e->getMessage());
        } finally {
            @unlink($dump);
        }
    }

    #[Test]
    public function doctor_refuses_directadmin_import_without_legacy(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = Node::factory()->create(['type' => 'container_host']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'container',
            'service_meta' => [],
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $result = app(ContainerDoctorService::class)->treat(
            $service->fresh(['product.containerTemplate', 'containerDeployment.node']),
            'import_da_database'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('da_legacy', $result['message']);
    }
}
