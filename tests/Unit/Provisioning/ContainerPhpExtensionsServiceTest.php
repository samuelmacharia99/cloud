<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\ContainerPhpExtensionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerPhpExtensionsServiceTest extends TestCase
{
    use RefreshDatabase;

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
            new Service(['service_meta' => []]),
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

    #[Test]
    public function it_persists_an_enabled_extension_without_dropping_other_meta(): void
    {
        $record = Service::factory()->create([
            'service_meta' => [
                'custom_field' => 'keep-me',
                'php_extensions' => ['exif'],
            ],
        ]);

        $keys = (new ContainerPhpExtensionsService)->applyExtensionPreference($record, 'redis', true);

        $this->assertSame(['exif', 'redis'], $keys);
        $record->refresh();
        $this->assertSame(['exif', 'redis'], $record->service_meta['php_extensions']);
        $this->assertSame('keep-me', $record->service_meta['custom_field']);
        $this->assertNotEmpty($record->service_meta['php_extensions_synced_at']);
    }

    #[Test]
    public function it_removes_an_extension_from_saved_preferences(): void
    {
        $record = Service::factory()->create([
            'service_meta' => [
                'php_extensions' => ['exif', 'redis'],
            ],
        ]);

        $keys = (new ContainerPhpExtensionsService)->applyExtensionPreference($record, 'redis', false);

        $this->assertSame(['exif'], $keys);
        $this->assertSame(['exif'], $record->fresh()->service_meta['php_extensions']);
    }

    #[Test]
    public function it_toggles_an_extension_off_without_connecting_to_the_container(): void
    {
        $record = Service::factory()->create([
            'service_meta' => [
                'php_extensions' => ['redis'],
            ],
        ]);
        $node = Node::factory()->create();
        $deployment = new ContainerDeployment([
            'status' => 'running',
            'node_id' => $node->id,
        ]);
        $deployment->setRelation('node', $node);

        $result = (new ContainerPhpExtensionsService)->toggle($record, $deployment, 'redis', false);

        $this->assertFalse($result['extension']['enabled']);
        $this->assertSame('redis', $result['extension']['key']);
        $this->assertSame([], $record->fresh()->service_meta['php_extensions']);
    }

    #[Test]
    public function it_rejects_unknown_extension_keys(): void
    {
        $record = Service::factory()->create(['service_meta' => []]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown PHP extension [not-a-real-ext].');

        (new ContainerPhpExtensionsService)->applyExtensionPreference($record, 'not-a-real-ext', true);
    }
}
