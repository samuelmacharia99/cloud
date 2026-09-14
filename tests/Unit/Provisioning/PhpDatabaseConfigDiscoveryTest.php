<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\DirectAdminToContainerMigrationService;
use App\Services\Provisioning\PhpDatabaseConfigDiscovery;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpDatabaseConfigDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/talksasa-dbdisc-'.uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function every_common_php_credential_shape_is_read(): void
    {
        $d = new PhpDatabaseConfigDiscovery;

        $defines = $d->parseSource("<?php\ndefine('DB_HOST', 'localhost');\ndefine( 'DB_USER', 'acme_app' );\ndefine(\"DB_PASSWORD\", \"p@ss'w\");\ndefine('DB_NAME', 'acme_site');");
        $this->assertSame(['DB_NAME' => 'acme_site', 'DB_USER' => 'acme_app', 'DB_PASSWORD' => "p@ss'w", 'DB_HOST' => 'localhost'], $defines);

        $vars = $d->parseSource("<?php\n\$servername = \"localhost\";\n\$username = \"blinksof_app\";\n\$password = \"s3cr3t!\";\n\$dbname = \"blinksof_portal\";\n\$conn = new mysqli(\$servername, \$username, \$password, \$dbname);");
        $this->assertSame(['DB_NAME' => 'blinksof_portal', 'DB_USER' => 'blinksof_app', 'DB_PASSWORD' => 's3cr3t!', 'DB_HOST' => 'localhost'], $vars);

        $connect = $d->parseSource("<?php \$link = mysqli_connect('127.0.0.1', 'shop_u', 'shop_p', 'shop_db');");
        $this->assertSame(['DB_NAME' => 'shop_db', 'DB_USER' => 'shop_u', 'DB_PASSWORD' => 'shop_p', 'DB_HOST' => '127.0.0.1'], $connect);

        $pdo = $d->parseSource("<?php \$pdo = new PDO(\"mysql:host=localhost;dbname=crm_main;charset=utf8\", 'crm_user', 'crm_pw');");
        $this->assertSame(['DB_NAME' => 'crm_main', 'DB_USER' => 'crm_user', 'DB_PASSWORD' => 'crm_pw', 'DB_HOST' => 'localhost'], $pdo);

        $ci3 = $d->parseSource("<?php\n\$db['default']['hostname'] = 'localhost';\n\$db['default']['username'] = 'ci_user';\n\$db['default']['password'] = 'ci_pw';\n\$db['default']['database'] = 'ci_db';");
        $this->assertSame(['DB_NAME' => 'ci_db', 'DB_USER' => 'ci_user', 'DB_PASSWORD' => 'ci_pw', 'DB_HOST' => 'localhost'], $ci3);

        $ci4 = $d->parseSource("<?php public array \$default = [\n 'hostname' => 'localhost',\n 'username' => 'ci4_user',\n 'password' => 'ci4_pw',\n 'database' => 'ci4_db',\n 'DBDriver' => 'MySQLi',\n];");
        $this->assertSame(['DB_NAME' => 'ci4_db', 'DB_USER' => 'ci4_user', 'DB_PASSWORD' => 'ci4_pw', 'DB_HOST' => 'localhost'], $ci4);

        $indirect = $d->parseSource("<?php define('DB_NAME', \$config['db']); \$username = \$env['user']; \$dbname = '';");
        $this->assertSame(['DB_NAME' => null, 'DB_USER' => null, 'DB_PASSWORD' => null, 'DB_HOST' => null], $indirect, 'values that are not literals are not trusted');
    }

    #[Test]
    public function the_hit_matching_a_directadmin_database_wins_and_vendor_decoys_lose(): void
    {
        $d = new PhpDatabaseConfigDiscovery;
        $root = '/home/blinksof/domains/blinksofttech.com/public_html';
        $output = implode("\n", [
            '==> '.$root.'/vendor/x/config.php',
            "1:define('DB_NAME', 'vendor_db'); define('DB_USER', 'vendor_u');",
            '==> '.$root.'/includes/config.php',
            '2:$username = "blinksof_app";',
            '3:$password = "s3cr3t!";',
            '4:$dbname = "blinksof_portal";',
            '==> '.$root.'/index.php',
            '10:echo "hello";',
        ]);

        $found = $d->parse($output, $root);
        $this->assertCount(2, $found);
        $chosen = $d->choose($found, ['blinksof_portal']);
        $this->assertSame($root.'/includes/config.php', $chosen['file']);
        $this->assertSame('blinksof_portal', $chosen['DB_NAME']);
        $this->assertNull($d->choose([], ['x']));
    }

    #[Test]
    public function the_command_finds_credentials_on_a_real_tree_and_skips_vendor(): void
    {
        File::ensureDirectoryExists($this->root.'/includes');
        File::ensureDirectoryExists($this->root.'/vendor/lib');
        File::put($this->root.'/index.php', "<?php require 'includes/config.php';\n");
        File::put($this->root.'/includes/config.php', "<?php\n\$servername = \"localhost\";\n\$username = \"blinksof_app\";\n\$password = \"s3cr3t!\";\n\$dbname = \"blinksof_portal\";\n");
        File::put($this->root.'/vendor/lib/config.php', "<?php define('DB_NAME', 'vendor_db');\n");
        $d = new PhpDatabaseConfigDiscovery;

        $output = (string) shell_exec('bash -c '.escapeshellarg($d->command($this->root)).' 2>&1');
        $found = $d->parse($output, $this->root);

        $this->assertCount(1, $found);
        $this->assertSame($this->root.'/includes/config.php', $found[0]['file']);
        $this->assertSame('blinksof_portal', $found[0]['DB_NAME']);
        $this->assertSame('blinksof_app', $found[0]['DB_USER']);
    }

    #[Test]
    public function import_targets_keep_directadmin_names_and_fall_back_to_the_sidecar(): void
    {
        $migrator = app(DirectAdminToContainerMigrationService::class);
        $sidecar = ['database' => 'appdb', 'user' => 'appuser', 'password' => 'sidecar-pw'];

        $targets = $migrator->importTargets('/tmp/legacy.sql', [
            ['path' => '/tmp/a.sql', 'db_name' => 'blinksof_portal', 'user' => 'blinksof_app', 'password' => 's3cr3t!'],
            ['path' => '/tmp/b.sql', 'db_name' => 'blinksof-odd;name', 'user' => '', 'password' => ''],
        ], $sidecar);

        $this->assertSame([
            ['path' => '/tmp/a.sql', 'database' => 'blinksof_portal', 'user' => 'blinksof_app', 'password' => 's3cr3t!'],
            ['path' => '/tmp/b.sql', 'database' => 'blinksofoddname', 'user' => 'appuser', 'password' => 'sidecar-pw'],
        ], $targets);

        $legacy = $migrator->importTargets('/tmp/legacy.sql', [], $sidecar);
        $this->assertSame([['path' => '/tmp/legacy.sql', 'database' => 'appdb', 'user' => 'appuser', 'password' => 'sidecar-pw']], $legacy);
        $this->assertSame([], $migrator->importTargets(null, [], $sidecar));

        $sql = $migrator->grantDatabaseUserSql("bl'nk", "p\\w'd", 'blinksof_portal');
        $this->assertStringContainsString("CREATE USER IF NOT EXISTS 'bl\\'nk'@'%' IDENTIFIED BY 'p\\\\w\\'d'", $sql);
        $this->assertStringContainsString('GRANT ALL PRIVILEGES ON `blinksof_portal`.*', $sql);
    }
}
