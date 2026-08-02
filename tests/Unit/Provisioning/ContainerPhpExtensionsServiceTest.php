<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerPhpExtensionsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerPhpExtensionsServiceTest extends TestCase
{
    #[Test]
    public function it_builds_gd_install_script_with_configure_step(): void
    {
        $service = new ContainerPhpExtensionsService;
        $script = $service->buildInstallScript('gd');

        $this->assertStringContainsString('libfreetype6-dev', $script);
        $this->assertStringContainsString('docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp', $script);
        $this->assertStringContainsString("docker-php-ext-install -j\"$(nproc)\" 'gd'", $script);
    }

    #[Test]
    public function it_hides_builtin_extensions_from_optional_panel_list(): void
    {
        $service = new ContainerPhpExtensionsService;
        $panel = $service->buildPanelState(
            new \App\Models\Service(['service_meta' => []]),
            null
        );

        $optionalKeys = array_column($panel['optional'], 'key');
        $this->assertNotContains('gd', $optionalKeys);
        $this->assertContains('gd', array_column($panel['builtin'], 'key'));
    }

    #[Test]
    public function it_builds_non_interactive_pecl_install_commands(): void
    {
        $service = new ContainerPhpExtensionsService;
        $script = $service->buildInstallScript('redis');

        $this->assertStringContainsString("pecl install -o -f 'redis'", $script);
        $this->assertStringContainsString("docker-php-ext-enable 'redis'", $script);
    }
}
