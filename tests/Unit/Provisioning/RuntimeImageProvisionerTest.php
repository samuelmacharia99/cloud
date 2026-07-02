<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Services\Provisioning\RuntimeImageProvisioner;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

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

    #[Test]
    public function it_appends_runtime_build_revision_to_image_tag(): void
    {
        config([
            'containers.runtime_registry' => 'talksasa',
            'containers.runtime_build_revision' => 2,
        ]);

        $provisioner = new RuntimeImageProvisioner;
        $template = new ContainerTemplate(['slug' => 'laravel']);

        $reference = $provisioner->resolveImageReference($template, '8.3');

        $this->assertSame('talksasa/laravel-runtime:8.3-r2', $reference['image']);
        $this->assertSame('8.3-r2', $reference['tag']);
        $this->assertSame('8.3', $reference['php_version']);
    }
}
