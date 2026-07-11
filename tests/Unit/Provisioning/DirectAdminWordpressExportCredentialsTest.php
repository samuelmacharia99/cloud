<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\DirectAdminToContainerMigrationService;
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
}
