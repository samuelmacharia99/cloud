<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerGitRepositoryServiceTest extends TestCase
{
    #[Test]
    public function it_normalizes_repository_urls_and_branches(): void
    {
        $service = new ContainerGitRepositoryService(new ContainerAppDirectoryService);

        $this->assertSame(
            'https://github.com/acme/app.git',
            $service->normalizeRepositoryUrl('https://github.com/acme/app.git')
        );
        $this->assertSame('main', $service->normalizeBranch(''));
        $this->assertSame('develop', $service->normalizeBranch('develop'));
    }

    #[Test]
    public function it_rejects_non_https_repository_urls(): void
    {
        $service = new ContainerGitRepositoryService(new ContainerAppDirectoryService);

        $this->expectException(\InvalidArgumentException::class);
        $service->normalizeRepositoryUrl('git@github.com:acme/app.git');
    }

    #[Test]
    public function it_supports_application_container_templates(): void
    {
        $service = new ContainerGitRepositoryService(new ContainerAppDirectoryService);

        $this->assertTrue($service->supportsTemplate('laravel'));
        $this->assertTrue($service->supportsTemplate('php'));
        $this->assertTrue($service->supportsTemplate('ruby'));
        $this->assertFalse($service->supportsTemplate('wordpress'));
    }

    #[Test]
    public function it_marks_container_git_directories_as_safe(): void
    {
        $service = new ContainerGitRepositoryService(new ContainerAppDirectoryService);
        $path = '/opt/talksasa/containers/user-4-service-82-nodejs/app';

        $this->assertSame(
            "git -c safe.directory='{$path}'",
            $service->gitInvocation($path)
        );
    }
}
