<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerAppDirectoryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerAppDirectoryServiceTest extends TestCase
{
    #[Test]
    public function it_treats_deploy_placeholder_paths_as_allowed(): void
    {
        $service = new ContainerAppDirectoryService;

        $this->assertTrue($service->isAllowedRelativePath('.keep'));
        $this->assertTrue($service->isAllowedRelativePath('index.html'));
        $this->assertTrue($service->isAllowedRelativePath('public'));
        $this->assertTrue($service->isAllowedRelativePath('public/index.html'));
        $this->assertTrue($service->isAllowedRelativePath('.talksasa'));
        $this->assertTrue($service->isAllowedRelativePath('.talksasa/bin/composer'));
    }

    #[Test]
    public function it_treats_application_files_as_blocking(): void
    {
        $service = new ContainerAppDirectoryService;

        $this->assertFalse($service->isAllowedRelativePath('artisan'));
        $this->assertFalse($service->isAllowedRelativePath('vendor'));
        $this->assertFalse($service->isAllowedRelativePath('myapp'));
        $this->assertFalse($service->isAllowedRelativePath('public/hot'));
    }

    #[Test]
    public function it_preserves_dependency_tree_permissions_when_normalizing_app_files(): void
    {
        $service = new ContainerAppDirectoryService;
        $script = $service->inContainerPermissionNormalizationScript();

        $this->assertStringContainsString('-path /app/node_modules', $script);
        $this->assertStringContainsString('-path /app/vendor', $script);
        $this->assertStringContainsString('-prune -o', $script);
        $this->assertStringContainsString('/node_modules/.bin', $script);
        $this->assertStringContainsString('chmod u+x', $script);
    }

    #[Test]
    public function it_normalizes_wordpress_files_under_var_www_html(): void
    {
        $service = new ContainerAppDirectoryService;
        $script = $service->inContainerPermissionNormalizationScript('/var/www/html');

        $this->assertStringContainsString('chown -R www-data:www-data /var/www/html', $script);
        $this->assertStringContainsString('-path /var/www/html/node_modules', $script);
        $this->assertStringNotContainsString('chown -R www-data:www-data /app;', $script);
    }

    #[Test]
    public function it_tolerates_missing_next_bin_directory_when_restoring_permissions(): void
    {
        $service = new ContainerAppDirectoryService;
        $script = $service->nodeModulesBinPermissionRestoreScript('/app');

        $this->assertStringContainsString('/app/node_modules/.bin', $script);
        $this->assertStringContainsString('/app/node_modules/next/dist/bin', $script);
        $this->assertStringContainsString('2>/dev/null || true', $script);
    }

    #[Test]
    public function it_prepares_nested_project_roots_for_composer_install(): void
    {
        $service = new ContainerAppDirectoryService;
        $script = $service->composerInstallPreparationScript('/app/core');

        $this->assertStringContainsString("root='/app/core'", $script);
        $this->assertStringContainsString('mkdir -p $root/vendor', $script);
        $this->assertStringContainsString('chown -R $owner /app', $script);
        $this->assertStringContainsString('chown -R $owner $root', $script);
        $this->assertStringContainsString('chmod -R ug+rwX $root', $script);
    }

    #[Test]
    public function it_creates_laravel_view_cache_directories_laravel_needs_at_boot(): void
    {
        $service = new ContainerAppDirectoryService;
        $script = $service->laravelWritableLayoutScript('/app');

        $this->assertStringContainsString('mkdir -p', $script);
        $this->assertStringContainsString('/app/storage/framework/views', $script);
        $this->assertStringContainsString('/app/storage/framework/cache/data', $script);
        $this->assertStringContainsString('/app/bootstrap/cache', $script);
        $this->assertStringContainsString('VIEW_COMPILED_PATH', $script);
        $this->assertStringContainsString('ln -sfn storage/logs', $script);
        $this->assertStringContainsString('/app/storage/logs', $script);
    }

    #[Test]
    public function writable_layout_probe_checks_www_data_can_write_logs(): void
    {
        $script = (new ContainerAppDirectoryService)->laravelWritableLayoutProbeScript('/app');

        $this->assertStringContainsString('storage/logs', $script);
        $this->assertStringContainsString('.talksasa-w', $script);
        $this->assertStringContainsString('fail missing:', $script);
    }
}
