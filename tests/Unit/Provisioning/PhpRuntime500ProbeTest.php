<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PhpRuntime500Probe;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpRuntime500ProbeTest extends TestCase
{
    #[Test]
    public function summary_prefers_the_captured_fatal_and_mentions_mysql_ext(): void
    {
        $probe = new PhpRuntime500Probe;

        $this->assertStringContainsString(
            'Call to undefined function mysql_connect()',
            $probe->summary([
                'fatal' => 'Fatal error: Call to undefined function mysql_connect()',
                'uses_mysql_ext' => true,
                'index_files' => ['/app/index.php (1200 bytes)'],
                'lint' => [],
            ])
        );
        $this->assertStringContainsString('mysql_*', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => true,
            'index_files' => ['/app/index.php (1200 bytes)'],
            'lint' => [],
        ]));
        $this->assertStringContainsString('No index.php', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => [],
            'lint' => [],
        ]));
        $this->assertStringContainsString('nginx FastCGI', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => ['/app/index.php (1200 bytes)'],
            'lint' => [],
        ]));
        $this->assertStringContainsString('not found under /app', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => ['/app/index.php (800 bytes)'],
            'lint' => [],
            'paths_php' => [],
            'index_require' => "require FCPATH . '../app/Config/Paths.php';",
        ]));
        $this->assertStringContainsString('localhost', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => ['/app/index.php (800 bytes)'],
            'lint' => [],
            'paths_php' => ['/app/app/Config/Paths.php'],
            'index_require' => "require __DIR__ . '/app/Config/Paths.php';",
            'ci_db_host' => 'localhost',
            'ci_system' => true,
            'ci_vendor_system' => false,
        ]));
        $this->assertStringContainsString('1045', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => ['/app/index.php (800 bytes)'],
            'lint' => [],
            'paths_php' => ['/app/app/Config/Paths.php'],
            'index_require' => "require __DIR__ . '/app/Config/Paths.php';",
            'ci_db_host' => 'user-483-service-426-static-site-db',
            'ci_db_user' => 'digiworl',
            'ci_db_name' => 'digiworl_roadtrip',
            'ci_pdo_error' => "SQLSTATE[HY000] [1045] Access denied for user 'digiworl'@'10.201.0.2'",
            'ci_system' => true,
            'ci_vendor_system' => true,
            'ci_encryption_key' => true,
            'ci_autoload' => true,
        ]));
        $this->assertStringContainsString('mysqli is not loaded', $probe->summary([
            'fatal' => null,
            'uses_mysql_ext' => false,
            'index_files' => ['/app/index.php (800 bytes)'],
            'lint' => [],
            'paths_php' => ['/app/app/Config/Paths.php'],
            'index_require' => "require __DIR__ . '/app/Config/Paths.php';",
            'ci_db_host' => 'user-483-service-426-static-site-db',
            'ci_db_user' => 'u483_s426',
            'ci_db_name' => 's426_db',
            'ci_system' => true,
            'ci_vendor_system' => true,
            'ci_encryption_key' => true,
            'ci_autoload' => true,
            'ci_mysqli' => false,
            'ci_db_driver' => 'MySQLi',
            'ci_http_body' => 'Server Error',
        ]));
    }

    #[Test]
    public function it_extracts_the_real_ci4_exception_not_the_production_server_error(): void
    {
        $probe = new PhpRuntime500Probe;

        $this->assertSame('Server Error', $probe->extractExceptionFromOutput('Server Error'));
        $this->assertTrue($probe->isGenericServerError('Server Error'));
        $this->assertSame(
            'Uncaught Error: Class "mysqli" not found in /app/system/Database/MySQLi/Connection.php:89',
            $probe->extractExceptionFromOutput('Uncaught Error: Class "mysqli" not found in /app/system/Database/MySQLi/Connection.php:89')
        );
        $this->assertSame(
            'Call to undefined function mysqli_connect()',
            $probe->extractExceptionFromOutput('<title>ErrorException</title><div class="exception-message">Call to undefined function mysqli_connect()</div>')
        );
    }
}
