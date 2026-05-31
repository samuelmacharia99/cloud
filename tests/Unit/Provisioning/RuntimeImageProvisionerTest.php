<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\RuntimeImageProvisioner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RuntimeImageProvisionerTest extends TestCase
{
    #[Test]
    public function it_normalizes_php_version_tags_from_selected_versions(): void
    {
        $provisioner = new RuntimeImageProvisioner;
        $method = new ReflectionMethod(RuntimeImageProvisioner::class, 'normalizePhpTag');
        $method->setAccessible(true);

        $this->assertSame('8.3', $method->invoke($provisioner, '8.3-cli', '8.2'));
        $this->assertSame('8.1', $method->invoke($provisioner, '8.1-cli', '8.3'));
        $this->assertSame('8.3', $method->invoke($provisioner, null, '8.3'));
    }
}
