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
}
