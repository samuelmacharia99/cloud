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
    }
}
