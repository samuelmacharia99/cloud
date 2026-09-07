<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PhpOsposAppInstaller;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpOsposAppInstallerTest extends TestCase
{
    #[Test]
    public function it_recognizes_ospos_and_missing_config_classes(): void
    {
        $installer = new PhpOsposAppInstaller;

        $this->assertTrue($installer->looksLikeOspos(null, true));
        $this->assertTrue($installer->looksLikeOspos('Class "Config\\Locale" not found'));
        $this->assertTrue($installer->looksLikeOspos('Class "Config\\Services" not found'));
        $this->assertFalse($installer->looksLikeOspos('encryption.key is empty', false));
        $this->assertSame('https://github.com/opensourcepos/opensourcepos.git', PhpOsposAppInstaller::REPOSITORY);
        $this->assertSame('master', PhpOsposAppInstaller::BRANCH);
    }
}
