<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerStackCommandService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerStackCommandServiceTest extends TestCase
{
    #[Test]
    public function it_blocks_long_running_setup_commands(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertTrue($service->isLongRunningCommand('npm start'));
        $this->assertTrue($service->isLongRunningCommand('rails server'));
        $this->assertFalse($service->isLongRunningCommand('npm install --omit=dev'));
        $this->assertFalse($service->isLongRunningCommand('bundle install --without development test'));
    }

    #[Test]
    public function it_resolves_workdirs_for_application_templates(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertSame('/app', $service->resolveWorkDir((object) [
            'slug' => 'nodejs',
            'volume_paths' => ['app_data' => '/app'],
        ]));
        $this->assertSame('/srv/app', $service->resolveWorkDir((object) [
            'slug' => 'strapi',
            'volume_paths' => ['strapi_app' => '/srv/app'],
        ]));
    }

    #[Test]
    public function it_rejects_unsafe_container_commands(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertFalse($service->isSafeCommand('npm install; rm -rf /'));
        $this->assertTrue($service->isSafeCommand('bundle install --without development test'));
    }

    #[Test]
    public function it_allows_npm_build_commands(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertFalse($service->isLongRunningCommand('npm run build'));
        $this->assertTrue($service->isSafeCommand('npm run build'));
        $this->assertTrue($service->isSafeCommand('npm prune --omit=dev'));
    }
}
