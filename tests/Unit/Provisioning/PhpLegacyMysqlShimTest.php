<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PhpLegacyMysqlShim;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpLegacyMysqlShimTest extends TestCase
{
    #[Test]
    public function it_detects_removed_mysql_extension_calls(): void
    {
        $shim = new PhpLegacyMysqlShim;

        $this->assertTrue($shim->usesRemovedMysqlExtension("<?php mysql_connect('localhost', 'u', 'p');"));
        $this->assertTrue($shim->usesRemovedMysqlExtension('$rows = mysql_query($sql);'));
        $this->assertFalse($shim->usesRemovedMysqlExtension("<?php \$db = new mysqli('host', 'u', 'p');"));
        $this->assertFalse($shim->usesRemovedMysqlExtension($shim->script()));
    }

    #[Test]
    public function it_polyfills_the_mysql_functions_directadmin_apps_call(): void
    {
        $script = (new PhpLegacyMysqlShim)->script();

        foreach ([
            'function mysql_connect',
            'function mysql_pconnect',
            'function mysql_select_db',
            'function mysql_query',
            'function mysql_fetch_array',
            'function mysql_fetch_assoc',
            'function mysql_result',
            'function mysql_real_escape_string',
            'function mysql_error',
            'function mysql_set_charset',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script);
        }

        $this->assertStringContainsString('auto_prepend_file=/app/.talksasa-mysql-shim.php', (new PhpLegacyMysqlShim)->userIniContents());
    }
}
