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

    public function test_wp_config_grep_command_is_valid_bash_c(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);
        $path = '/home/benwooda/domains/benwood.africa/public_html/wp-config.php';
        $cmd = $service->wpConfigDatabaseDefineGrepCommand($path);

        $this->assertStringStartsWith('grep -E ', $cmd);
        $this->assertStringNotContainsString('grep -E "', $cmd);

        $syntax = [];
        $code = 0;
        exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
        $this->assertSame(0, $code, implode("\n", $syntax));
    }

    public function test_wp_config_php_parse_command_passes_path_via_env(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);
        $path = '/home/benwooda/domains/benwood.africa/public_html/wp-config.php';
        $cmd = $service->wpConfigDatabasePhpParseCommand($path);

        $this->assertStringContainsString('TALKSASA_WP_CONFIG=', $cmd);
        $this->assertStringContainsString('php -r ', $cmd);
        $this->assertStringNotContainsString('$argv', $cmd);

        $syntax = [];
        $code = 0;
        exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
        $this->assertSame(0, $code, implode("\n", $syntax));
    }

    public function test_parse_wp_config_define_lines_accepts_spaced_defines(): void
    {
        $raw = <<<'PHP'
define( 'DB_NAME', 'benwooda_wp' );
define( "DB_USER", "benwooda_wpuser" );
define('DB_PASSWORD','p@ss with spaces');
define('DB_HOST', 'localhost:/var/lib/mysql/mysql.sock');
PHP;

        $creds = app(DirectAdminToContainerMigrationService::class)->parseWpConfigDefineLines($raw, [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ]);

        $this->assertSame('benwooda_wp', $creds['DB_NAME']);
        $this->assertSame('benwooda_wpuser', $creds['DB_USER']);
        $this->assertSame('p@ss with spaces', $creds['DB_PASSWORD']);
        $this->assertSame('localhost:/var/lib/mysql/mysql.sock', $creds['DB_HOST']);
    }

    public function test_parse_wp_config_define_lines_reads_multiline_and_getenv_docker(): void
    {
        $raw = <<<'PHP'
<?php
define(
	'DB_NAME',
	'benwooda_wp'
);
define( 'DB_USER', getenv_docker('WORDPRESS_DB_USER', 'benwooda_wpuser') );
define('DB_PASSWORD', 'secret');
define('DB_HOST', 'localhost');
PHP;

        $creds = app(DirectAdminToContainerMigrationService::class)->parseWpConfigDefineLines($raw, [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ]);

        $this->assertSame('benwooda_wp', $creds['DB_NAME']);
        $this->assertSame('benwooda_wpuser', $creds['DB_USER']);
        $this->assertSame('secret', $creds['DB_PASSWORD']);
    }

    public function test_parse_wp_config_define_lines_reads_variable_and_elvis_getenv(): void
    {
        $raw = <<<'PHP'
<?php
$dbname = 'benwooda_wp';
define('DB_NAME', $dbname);
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'benwooda_wpuser');
define('DB_PASSWORD', 'secret', false);
PHP;

        $creds = app(DirectAdminToContainerMigrationService::class)->parseWpConfigDefineLines($raw, [
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ]);

        $this->assertSame('benwooda_wp', $creds['DB_NAME']);
        $this->assertSame('benwooda_wpuser', $creds['DB_USER']);
        $this->assertSame('secret', $creds['DB_PASSWORD']);
    }

    public function test_wp_config_referenced_paths_follow_require_not_wp_settings(): void
    {
        $raw = <<<'PHP'
<?php
require_once '/home/benwooda/wp-config.php';
require_once __DIR__ . '/wp-settings.php';
include 'wp-config-local.php';
PHP;

        $paths = app(DirectAdminToContainerMigrationService::class)->wpConfigReferencedPaths(
            '/home/benwooda/domains/benwood.africa/public_html/wp-config.php',
            $raw
        );

        $this->assertContains('/home/benwooda/wp-config.php', $paths);
        $this->assertContains('/home/benwooda/domains/benwood.africa/public_html/wp-config-local.php', $paths);
        $this->assertNotContains('/home/benwooda/domains/benwood.africa/public_html/wp-settings.php', $paths);
    }

    public function test_wp_config_candidate_paths_include_user_home(): void
    {
        $paths = app(DirectAdminToContainerMigrationService::class)->wpConfigCandidatePaths(
            '/home/benwooda/domains/benwood.africa/public_html',
            null,
            'benwooda'
        );

        $this->assertContains('/home/benwooda/domains/benwood.africa/public_html/wp-config.php', $paths);
        $this->assertContains('/home/benwooda/wp-config.php', $paths);
    }

    public function test_remote_cat_and_grep_commands_are_valid_bash(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);
        $cat = $service->remoteCatCommand('/home/benwooda/domains/benwood.africa/public_html/wp-config.php');
        $grep = $service->remoteGrepFileCommand(
            '/usr/local/directadmin/conf/mysql.conf',
            '^(user|passwd|host)='
        );

        foreach ([$cat, $grep] as $cmd) {
            $syntax = [];
            $code = 0;
            exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
            $this->assertSame(0, $code, $cmd."\n".implode("\n", $syntax));
        }

        $this->assertStringContainsString('sudo -n cat', $cat);
        $this->assertStringContainsString('grep -E ', $grep);
        $this->assertStringNotContainsString('grep -E "', $grep);
    }

    public function test_filter_application_mysql_database_names_scopes_to_da_user(): void
    {
        $raw = "information_schema\nmysql\nbenwooda_wp\nroundcube\notheruser_wp\n";

        $this->assertSame(
            ['benwooda_wp'],
            app(DirectAdminToContainerMigrationService::class)->filterApplicationMysqlDatabaseNames($raw, 'benwooda')
        );
        $this->assertSame(
            [],
            app(DirectAdminToContainerMigrationService::class)->filterApplicationMysqlDatabaseNames($raw, '')
        );
    }

    public function test_pick_preferred_mysql_database_name_prefers_username_prefix(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $this->assertSame('benwooda_wp', $service->pickPreferredMysqlDatabaseName(
            ['roundcube', 'benwooda_wp'],
            'benwooda'
        ));
        $this->assertSame('benwooda_wpuser', $service->pickPreferredMysqlDatabaseName(
            ['benwooda_wp', 'benwooda_wpuser'],
            'benwooda',
            ['DB_USER' => 'benwooda_wpuser']
        ));
        $this->assertSame('onlydb', $service->pickPreferredMysqlDatabaseName(['onlydb'], 'benwooda'));
        $this->assertNull($service->pickPreferredMysqlDatabaseName([], 'benwooda'));
    }

    public function test_parse_mysql_conf_basename_list_ignores_glob(): void
    {
        $raw = "benwooda_wp.conf\n*.conf\nbenwooda_wp2\n";

        $this->assertSame(
            ['benwooda_wp', 'benwooda_wp2'],
            app(DirectAdminToContainerMigrationService::class)->parseMysqlConfBasenameList($raw)
        );
    }

    public function test_list_directadmin_mysql_names_command_is_valid_bash(): void
    {
        $cmd = app(DirectAdminToContainerMigrationService::class)
            ->listDirectAdminUserMysqlDatabaseNamesCommand('benwooda');

        $syntax = [];
        $code = 0;
        exec('bash -n -c '.escapeshellarg($cmd).' 2>&1', $syntax, $code);
        $this->assertSame(0, $code, implode("\n", $syntax));
        $this->assertStringContainsString('/users/benwooda/mysql', $cmd);
        $this->assertStringContainsString('find ', $cmd);
        $this->assertStringContainsString('sudo -n find', $cmd);
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

    public function test_build_mysql_dump_import_command_pipes_host_file_into_compose_mysql(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildMysqlDumpImportCommand(
            '/opt/talksasa/containers/demo',
            'mysql',
            '/tmp/db.sql',
            'wordpress',
            'secret',
            'wordpress',
        );

        $this->assertStringContainsString("cat '/tmp/db.sql'", $cmd);
        $this->assertStringContainsString("docker compose exec -T -e MYSQL_PWD='secret' 'mysql'", $cmd);
        $this->assertStringContainsString("mysql -u'wordpress' 'wordpress'", $cmd);
        $this->assertStringNotContainsString('sh -c', $cmd);
        $this->assertStringNotContainsString('/tmp/import.sql', $cmd);
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

    public function test_build_wordpress_permissions_command_owns_files_as_www_data_uid(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildWordPressPermissionsCommand(
            '/opt/talksasa/containers/user-76-service-97-wordpress/app'
        );

        $this->assertStringContainsString('chown -R 33:33', $cmd);
        $this->assertStringContainsString('wp-config.php', $cmd);
        $this->assertStringContainsString('chmod 640', $cmd);
        $this->assertStringContainsString('wp-content', $cmd);
        $this->assertStringContainsString('chmod -R', $cmd);
    }

    public function test_build_wordpress_runtime_sanitize_clears_cpanel_session_path(): void
    {
        $service = app(DirectAdminToContainerMigrationService::class);

        $cmd = $service->buildWordPressRuntimeSanitizeCommand(
            '/opt/talksasa/containers/user-76-service-97-wordpress/app'
        );

        $this->assertStringContainsString('session\\.save_path', $cmd);
        $this->assertStringContainsString('.user.ini', $cmd);
        $this->assertStringContainsString('/var/www/html/wp-content/uploads/sessions', $cmd);
        $this->assertStringContainsString('.htaccess', $cmd);
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
