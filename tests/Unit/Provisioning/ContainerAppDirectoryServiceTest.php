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
}
