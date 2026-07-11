<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\SSH\SSHService;
use Tests\TestCase;

class DirectAdminWordpressExportCredentialsTest extends TestCase
{
    public function test_decode_wp_database_credential_lines(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);
        $output = implode("\n", [
            'DB_NAME:'.base64_encode('sisallov_wp_ghvrh'),
            'DB_USER:'.base64_encode('sisallov_wpuser'),
            'DB_PASSWORD:'.base64_encode('p@ss:with=chars'),
            'DB_HOST:'.base64_encode('localhost'),
        ]);

        $creds = $service->decodeWpDatabaseCredentialLines($output);

        $this->assertSame('sisallov_wp_ghvrh', $creds['DB_NAME']);
        $this->assertSame('sisallov_wpuser', $creds['DB_USER']);
        $this->assertSame('p@ss:with=chars', $creds['DB_PASSWORD']);
        $this->assertSame('localhost', $creds['DB_HOST']);
    }

    public function test_build_mysql_dump_command_uses_wp_credentials_not_root(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildMysqlDumpCommand([
            'DB_USER' => 'sisallov_wpuser',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => 'localhost',
        ], 'sisallov_wp_ghvrh', '/tmp/db.sql');

        $this->assertStringContainsString("MYSQL_PWD='secret'", $cmd);
        $this->assertStringContainsString("-u'sisallov_wpuser'", $cmd);
        $this->assertStringContainsString("-h'localhost'", $cmd);
        $this->assertStringContainsString("'sisallov_wp_ghvrh'", $cmd);
        $this->assertStringNotContainsString('root', $cmd);
    }

    public function test_build_mysql_dump_command_with_defaults_file(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildMysqlDumpCommand([
            'DB_USER' => 'sisallov_wpuser',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => 'localhost',
        ], 'sisallov_wp_ghvrh', '/tmp/db.sql', '/tmp/mysqldump.cnf');

        $this->assertStringStartsWith("mysqldump --defaults-extra-file='/tmp/mysqldump.cnf'", $cmd);
        $this->assertStringNotContainsString('MYSQL_PWD', $cmd);
        $this->assertStringNotContainsString('root', $cmd);
        // Ensure defaults-extra-file is not after other mysqldump options
        $this->assertMatchesRegularExpression(
            "/^mysqldump --defaults-extra-file='[^']+' --single-transaction/",
            $cmd
        );
    }

    public function test_build_mysql_dump_command_supports_socket_host(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildMysqlDumpCommand([
            'DB_USER' => 'u',
            'DB_PASSWORD' => 'p',
            'DB_HOST' => 'localhost:/var/lib/mysql/mysql.sock',
        ], 'db', '/tmp/db.sql');

        $this->assertStringContainsString("--socket='/var/lib/mysql/mysql.sock'", $cmd);
    }

    public function test_build_wordpress_files_tar_command_tolerates_changed_files(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildWordPressFilesTarCommand(
            '/home/sisallov/domains/sisallove.com/public_html',
            '/opt/talksasa/da-migrations/wp-export/files.tar.gz'
        );

        $this->assertStringContainsString("tar -czf '/opt/talksasa/da-migrations/wp-export/files.tar.gz'", $cmd);
        $this->assertStringContainsString("--exclude='./wp-content/cache'", $cmd);
        $this->assertStringContainsString('status=$?', $cmd);
        $this->assertStringContainsString('[ "$status" -eq 1 ]', $cmd);
        $this->assertStringContainsString('[ -s ', $cmd);
    }

    public function test_build_wordpress_host_extract_command_targets_file_manager_path(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildWordPressHostExtractCommand(
            '/tmp/files.tar.gz',
            '/opt/talksasa/containers/user-76-service-97-wordpress/app'
        );

        $this->assertStringContainsString(
            "tar -xzf '/tmp/files.tar.gz' -C '/opt/talksasa/containers/user-76-service-97-wordpress/app'",
            $cmd
        );
        $this->assertStringContainsString('wp-config.php', $cmd);
        $this->assertStringContainsString('wp-content', $cmd);
    }

    public function test_resolve_wordpress_import_credentials_prefers_deployment_env_values(): void
    {
        $ssh = \Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command): string {
            if (str_contains($command, 'grep')) {
                return '';
            }

            return "mysql\nwordpress-app\n";
        });

        $deployment = new ContainerDeployment([
            'env_values' => [
                'WORDPRESS_DB_NAME' => 'wordpress',
                'WORDPRESS_DB_USER' => 'wordpress',
                'WORDPRESS_DB_PASSWORD' => 'app-secret',
                'MYSQL_ROOT_PASSWORD' => 'root-secret',
            ],
        ]);

        $service = new Service;
        $service->setRelation('containerDeployment', $deployment);

        $migrator = app(DirectAdminToContainerMigrationService::class);
        $creds = $migrator->resolveWordpressImportCredentials(
            $service,
            $ssh,
            '/opt/talksasa/containers/demo'
        );

        $this->assertSame('mysql', $creds['service']);
        $this->assertSame('wordpress', $creds['database']);
        $this->assertSame('wordpress', $creds['user']);
        $this->assertSame('app-secret', $creds['password']);
        $this->assertSame('root-secret', $creds['root_password']);
    }
}
