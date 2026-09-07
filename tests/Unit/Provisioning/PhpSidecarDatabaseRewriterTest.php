<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PhpSidecarDatabaseRewriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpSidecarDatabaseRewriterTest extends TestCase
{
    #[Test]
    public function it_rewrites_directadmin_defines_and_mysqli_localhost(): void
    {
        $source = <<<'PHP'
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'digiworl_roadtrip');
define('DB_USER', 'digiworl');
define('DB_PASSWORD', 'old-secret');
$mysqli = new mysqli('localhost', 'digiworl', 'old-secret', 'digiworl_roadtrip');
PHP;

        $rewritten = (new PhpSidecarDatabaseRewriter)->rewritePhpSource(
            $source,
            [
                'host' => 'user-483-service-426-static-site-db',
                'database' => 's426_db',
                'username' => 'u483_s426',
                'password' => 'sidecar-secret',
            ],
            ['digiworl_roadtrip'],
            ['digiworl']
        );

        $this->assertStringContainsString("define('DB_HOST', 'user-483-service-426-static-site-db')", $rewritten);
        $this->assertStringContainsString("define('DB_NAME', 's426_db')", $rewritten);
        $this->assertStringContainsString("define('DB_USER', 'u483_s426')", $rewritten);
        $this->assertStringContainsString("define('DB_PASSWORD', 'sidecar-secret')", $rewritten);
        $this->assertStringContainsString("new mysqli('user-483-service-426-static-site-db'", $rewritten);
        $this->assertStringNotContainsString('localhost', $rewritten);
        $this->assertStringNotContainsString('digiworl_roadtrip', $rewritten);
    }

    #[Test]
    public function it_rewrites_pdo_dsn_and_unix_socket_hosts(): void
    {
        $source = "\$pdo = new PDO('mysql:host=localhost;dbname=digiworl_roadtrip', 'u', 'p');\n"
            ."\$alt = new PDO('mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=app');\n";

        $rewritten = (new PhpSidecarDatabaseRewriter)->rewritePhpSource(
            $source,
            [
                'host' => 'site-db',
                'database' => 's426_db',
                'username' => 'u483_s426',
                'password' => 'x',
            ],
            ['digiworl_roadtrip']
        );

        $this->assertStringContainsString('mysql:host=site-db;dbname=s426_db', $rewritten);
        $this->assertStringContainsString('mysql:host=site-db;dbname=app', $rewritten);
        $this->assertStringNotContainsString('unix_socket', $rewritten);
    }

    #[Test]
    public function it_pins_dotenv_and_strips_directadmin_socket_keys(): void
    {
        $env = "APP_NAME=Roadtrip\nDB_HOST=localhost\nDB_SOCKET=/var/lib/mysql/mysql.sock\nDB_DATABASE=old\n";

        $rewritten = (new PhpSidecarDatabaseRewriter)->rewriteEnv($env, [
            'host' => 'site-db',
            'database' => 's426_db',
            'username' => 'u483_s426',
            'password' => 'secret',
        ]);

        $this->assertStringContainsString('DB_HOST=site-db', $rewritten);
        $this->assertStringContainsString('DB_DATABASE=s426_db', $rewritten);
        $this->assertStringContainsString('DB_USERNAME=u483_s426', $rewritten);
        $this->assertStringNotContainsString('DB_SOCKET=', $rewritten);
        $this->assertStringContainsString('APP_NAME=Roadtrip', $rewritten);
        $this->assertStringContainsString('database.default.hostname=site-db', $rewritten);
    }

    #[Test]
    public function it_rewrites_codeigniter_env_and_database_php_assignments(): void
    {
        $rewriter = new PhpSidecarDatabaseRewriter;
        $env = "CI_ENVIRONMENT = production\ndatabase.default.hostname = localhost\ndatabase.default.database = digiworl_roadtrip\n";
        $rewrittenEnv = $rewriter->rewriteEnv($env, [
            'host' => 'user-483-service-426-static-site-db',
            'database' => 's426_db',
            'username' => 'u483_s426',
            'password' => 'sidecar-secret',
        ]);
        $this->assertStringContainsString('database.default.hostname=user-483-service-426-static-site-db', $rewrittenEnv);
        $this->assertStringContainsString('database.default.database=s426_db', $rewrittenEnv);
        $this->assertStringNotContainsString('database.default.hostname = localhost', $rewrittenEnv);

        $source = <<<'PHP'
public array $default = [
    'hostname' => 'localhost',
    'username' => 'digiworl',
    'password' => 'old',
    'database' => 'digiworl_roadtrip',
    'port'     => 3306,
];
PHP;
        $rewritten = $rewriter->rewriteCodeIgniterDatabaseAssignments($source, [
            'host' => 'user-483-service-426-static-site-db',
            'database' => 's426_db',
            'username' => 'u483_s426',
            'password' => 'sidecar-secret',
            'port' => '3306',
        ]);
        $this->assertStringContainsString("'hostname' => 'user-483-service-426-static-site-db'", $rewritten);
        $this->assertStringContainsString("'database' => 's426_db'", $rewritten);
        $this->assertStringNotContainsString('localhost', $rewritten);
        $this->assertStringNotContainsString('digiworl_roadtrip', $rewritten);
    }

    #[Test]
    public function it_parses_quoted_env_assignments_from_a_host_dotenv(): void
    {
        $parsed = (new PhpSidecarDatabaseRewriter)->parseEnvAssignments(
            "APP_NAME=Roadtrip\n# comment\nDB_PASSWORD=\"p@ss word\"\nMYSQL_USER=u483_s426\n"
        );

        $this->assertSame('p@ss word', $parsed['DB_PASSWORD']);
        $this->assertSame('u483_s426', $parsed['MYSQL_USER']);
        $this->assertSame('Roadtrip', $parsed['APP_NAME']);
    }
}
