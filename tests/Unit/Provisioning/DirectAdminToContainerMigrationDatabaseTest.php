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
use Illuminate\Support\Facades\File;
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
        $this->assertTrue($migrator->canImportDirectAdminCodeIgniterSiblings($service));
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

    #[Test]
    public function it_locates_codeigniter_app_next_to_public_html(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/u483/domains/roadtrip.example.com/public_html';

        $this->assertContains(
            '/home/u483/domains/roadtrip.example.com/app/Config/Paths.php',
            $migrator->codeIgniterSiblingProbePaths($docroot)
        );

        $located = $migrator->locateCodeIgniterProjectRoot([
            '/home/u483/domains/roadtrip.example.com/app/Config/Paths.php',
        ]);
        $this->assertSame('/home/u483/domains/roadtrip.example.com', $located['project_root'] ?? null);

        $tar = $migrator->buildCodeIgniterSiblingTarCommand(
            '/home/u483/domains/roadtrip.example.com',
            '/tmp/ci.tar.gz',
            ['app', 'writable', 'vendor']
        );
        $this->assertStringContainsString("-C '/home/u483/domains/roadtrip.example.com'", $tar);
        $this->assertStringContainsString("'app'", $tar);
        $this->assertStringContainsString("'writable'", $tar);
        $this->assertStringNotContainsString('public_html', $tar);
    }

    #[Test]
    public function it_finds_codeigniter_under_the_da_user_home_not_only_beside_public_html(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $docroot = '/home/digiworl/domains/roadtrip.digiworldmediasln.com/public_html';

        $this->assertContains('/home/digiworl', $migrator->codeIgniterSearchRoots($docroot, 'digiworl'));
        $this->assertContains(
            '/home/digiworl/domains/roadtrip.digiworldmediasln.com',
            $migrator->codeIgniterSearchRoots($docroot, 'digiworl')
        );
        $this->assertContains('/home/digiworl/public_html', $migrator->codeIgniterSearchRoots($docroot, 'digiworl'));
        $this->assertContains('/opt/talksasa/da-migrations', $migrator->codeIgniterSearchRoots($docroot, 'digiworl'));

        $cmd = $migrator->buildCodeIgniterSearchCommand('/home/digiworl');
        $this->assertStringContainsString("find -L '/home/digiworl'", $cmd);
        $this->assertStringContainsString('Config/Paths.php', $cmd);
        $this->assertStringContainsString('-iname spark', $cmd);

        $this->assertSame(
            ['roadtrip.digiworldmediasln.com', 'roadtrip'],
            $migrator->codeIgniterDomainNeedles($docroot)
        );

        $this->assertSame(
            '/home/digiworl/roadtrip/app/Config/Paths.php',
            $migrator->preferCodeIgniterHit([
                '/home/digiworl/old-backup/app/Config/Paths.php',
                '/home/digiworl/roadtrip/app/Config/Paths.php',
            ], $docroot)
        );

        $this->assertNull(
            $migrator->preferCodeIgniterHit([
                '/home/other/site-a/app/Config/Paths.php',
                '/home/other/site-b/app/Config/Paths.php',
            ], $docroot)
        );

        $fromSpark = $migrator->locateCodeIgniterProjectRoot([
            '/home/digiworl/ci4/spark',
        ]);
        $this->assertSame('/home/digiworl/ci4', $fromSpark['project_root'] ?? null);

        $fromLower = $migrator->locateCodeIgniterProjectRoot([
            '/home/digiworl/ci4/app/config/Paths.php',
        ]);
        $this->assertSame('/home/digiworl/ci4', $fromLower['project_root'] ?? null);

        $merged = $migrator->mergeDiscoveredSearchRoots(
            [$docroot],
            '/home/digiworl',
            "/home/digiworl/domains/roadtrip.digiworldmediasln.com\n/etc/passwd"
        );
        $this->assertContains('/home/digiworl', $merged);
        $this->assertContains('/home/digiworl/domains/roadtrip.digiworldmediasln.com', $merged);
        $this->assertNotContains('/etc/passwd', $merged);

        $targeted = $migrator->buildCodeIgniterTargetedSearchCommand('/home/digiworl', 'roadtrip');
        $this->assertStringContainsString('*roadtrip*/Config/Paths.php', $targeted);

        $bake = $migrator->buildBakeCodeIgniterSiblingsIntoTarCommand(
            '/tmp/files.tar.gz',
            '/home/digiworl/domains/roadtrip.digiworldmediasln.com',
            ['app', 'writable']
        );
        $this->assertStringContainsString('cp -a', $bake);
        $this->assertStringContainsString("'/home/digiworl/domains/roadtrip.digiworldmediasln.com/app'", $bake);
    }

    #[Test]
    public function uploads_are_packed_from_directadmin_and_unpacked_without_replacing_what_the_container_already_has(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $root = sys_get_temp_dir().'/talksasa-uploads-'.uniqid();
        $da = $root.'/da/public_html';
        $app = $root.'/container/app';
        File::ensureDirectoryExists($da.'/wp-content/uploads/2026/09');
        File::ensureDirectoryExists($da.'/wp-content/uploads/cache');
        File::ensureDirectoryExists($da.'/wp-content/uploads/sessions');
        File::ensureDirectoryExists($app.'/wp-content/uploads/2026/09');
        File::put($da.'/wp-content/uploads/2026/09/lost.jpg', 'da-copy');
        File::put($da.'/wp-content/uploads/2026/09/shared.jpg', 'da-copy');
        File::put($da.'/wp-content/uploads/cache/x.tmp', 'cache');
        File::put($da.'/wp-content/uploads/sessions/sess_1', 'sess');
        File::put($app.'/wp-content/uploads/2026/09/shared.jpg', 'newer-container-copy');
        $tar = $root.'/uploads.tar.gz';

        try {
            $uploads = $da.'/wp-content/uploads';
            foreach ([$migrator->buildUploadsSizeCommand($uploads), $migrator->buildUploadsTarCommand($uploads, $tar), $migrator->buildUploadsExtractCommand($tar, $app), $migrator->buildUploadsDiscoveryCommand($da, dirname($da, 3))] as $command) {
                exec('bash -n -c '.escapeshellarg($command).' 2>&1', $syntax, $code);
                $this->assertSame(0, $code, implode("\n", $syntax));
            }

            $this->assertGreaterThan(0, (int) trim((string) shell_exec('bash -c '.escapeshellarg($migrator->buildUploadsSizeCommand($uploads)))));
            shell_exec('bash -c '.escapeshellarg($migrator->buildUploadsTarCommand($uploads, $tar)).' 2>&1');
            $this->assertFileExists($tar);
            $entries = (string) shell_exec('tar -tzf '.escapeshellarg($tar));
            $this->assertStringContainsString('./uploads/2026/09/lost.jpg', $entries);
            $this->assertStringNotContainsString('uploads/cache/', $entries);
            $this->assertStringNotContainsString('uploads/sessions/', $entries);

            shell_exec('bash -c '.escapeshellarg($migrator->buildUploadsExtractCommand($tar, $app)).' 2>&1');
            $this->assertSame('da-copy', (string) file_get_contents($app.'/wp-content/uploads/2026/09/lost.jpg'), 'the missing file is filled in');
            $this->assertSame('newer-container-copy', (string) file_get_contents($app.'/wp-content/uploads/2026/09/shared.jpg'), 'a file already on the container is never replaced');
            $this->assertFileDoesNotExist($tar, 'the staged archive is removed');
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[Test]
    public function the_doctor_offers_the_copy_only_with_a_convert_record_and_relays_the_migrators_answer(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $node = Node::factory()->create(['type' => 'container_host', 'ip_address' => '10.0.0.7']);
        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'node_id' => $node->id,
            'status' => 'active',
            'service_meta' => [],
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id, 'node_id' => $node->id, 'status' => 'running', 'container_name' => 'user-1-service-77-wordpress']);

        $this->assertFalse($migrator->canRepullDirectAdminFiles($service->fresh()));
        $refused = app(ContainerDoctorService::class)->treat($service->fresh(['containerDeployment.node', 'product.containerTemplate']), 'import_da_uploads');
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('no DirectAdmin convert record', $refused['message']);

        $service->update(['service_meta' => ['da_legacy' => ['username' => 'whsafari', 'da_node_id' => $node->id, 'domain' => 'whsafaris.co.ke', 'docroot' => '/home/whsafari/domains/whsafaris.co.ke/public_html', 'stack' => 'wordpress']]]);
        $this->assertTrue($migrator->canRepullDirectAdminFiles($service->fresh()));

        $this->mock(DirectAdminToContainerMigrationService::class, function ($mock) {
            $mock->shouldReceive('canRepullDirectAdminFiles')->andReturn(true);
            $mock->shouldReceive('pullWordPressUploadsFromDirectAdmin')->once()
                ->andReturn(['files' => 412, 'bytes' => 52428800, 'da_node_id' => 1, 'skipped' => false, 'message' => 'Copied wp-content/uploads from DirectAdmin (412 file(s), 50 MB).']);
        });
        $done = app(ContainerDoctorService::class)->treat($service->fresh(['containerDeployment.node', 'product.containerTemplate']), 'import_da_uploads');
        $this->assertTrue($done['success'], $done['message']);
        $this->assertStringContainsString('412 file(s)', $done['message']);
        $this->assertStringContainsString('Rebuild thumbnails', $done['message']);
    }

    #[Test]
    public function uploads_are_found_under_the_real_domain_folder_when_the_record_names_the_www_twin_or_a_subfolder(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $root = sys_get_temp_dir().'/talksasa-discover-'.uniqid();
        $home = $root.'/home/whsafari';
        $recorded = $home.'/domains/www.whsafaris.co.ke/public_html';
        $real = $home.'/domains/whsafaris.co.ke/public_html';
        $nested = $home.'/domains/other.co.ke/public_html/blog';
        File::ensureDirectoryExists($recorded);
        File::ensureDirectoryExists($real.'/wp-content/uploads/2026/09');
        File::ensureDirectoryExists($nested.'/wp-content/uploads/2024/01');
        File::put($real.'/wp-content/uploads/2026/09/a.jpg', str_repeat('x', 4000));
        File::put($nested.'/wp-content/uploads/2024/01/b.jpg', str_repeat('y', 100));

        try {
            $command = $migrator->buildUploadsDiscoveryCommand($recorded, $home);
            $candidates = $migrator->parseUploadsCandidates((string) shell_exec('bash -c '.escapeshellarg($command).' 2>&1'));

            $paths = array_column($candidates, 'path');
            $this->assertContains($real.'/wp-content/uploads', $paths, 'the non-www twin of the recorded docroot is checked');
            $this->assertContains($nested.'/wp-content/uploads', $paths, 'a site one folder down is found too');
            $this->assertNotContains($recorded.'/wp-content/uploads', $paths, 'the recorded docroot has none');

            $this->assertSame($real.'/wp-content/uploads', $migrator->chooseUploadsDir($candidates, $recorded), 'the fuller library with year folders wins');
            $this->assertSame($recorded.'/wp-content/uploads', $migrator->chooseUploadsDir(array_merge($candidates, [['path' => $recorded.'/wp-content/uploads', 'bytes' => 10, 'years' => false]]), $recorded), 'a recorded docroot that has files still wins');
            $this->assertNull($migrator->chooseUploadsDir([['path' => '/x', 'bytes' => 0, 'years' => false]], $recorded), 'an empty folder is no source');
        } finally {
            File::deleteDirectory($root);
        }
    }
}
