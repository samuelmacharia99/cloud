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
    }
}
