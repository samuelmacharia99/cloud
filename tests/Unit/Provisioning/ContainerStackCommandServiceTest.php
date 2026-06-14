<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerStackCommandService;
use App\Services\SSH\SSHService;
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
        $this->assertTrue($service->isSafeCommand('rm -rf node_modules'));
        $this->assertTrue($service->isSafeCommand('env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_ENV=development /usr/local/bin/npm install --production=false --include=dev --no-audit --no-fund'));
    }

    #[Test]
    public function it_passes_environment_overrides_to_one_off_containers(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->once())
            ->method('exec')
            ->with($this->callback(fn (string $command): bool => str_contains($command, 'docker compose run --rm -T')
                && str_contains($command, ' sh -c ')
                && str_contains($command, 'env -i HOME=/tmp')
                && str_contains($command, '/usr/local/bin/npm install --production=false --include=dev')))
            ->willReturn('');

        $service->runOneOffInContainer(
            $ssh,
            '/var/lib/talksasa/containers/user-1-service-1',
            'user-1-service-1-nodejs',
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_ENV=development /usr/local/bin/npm install --production=false --include=dev --no-audit --no-fund',
            '/app',
            120,
            [
                'NPM_CONFIG_PRODUCTION' => 'false',
                'npm_config_production' => 'false',
            ]
        );
    }

    #[Test]
    public function it_runs_unlimited_memory_node_builds_with_docker_run(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->once())
            ->method('exec')
            ->with($this->callback(fn (string $command): bool => str_contains($command, 'docker run --rm -v ')
                && str_contains($command, 'node:20-alpine')
                && str_contains($command, '/usr/local/bin/npm run build')))
            ->willReturn('');

        $service->runUnlimitedMemoryNodeCommand(
            $ssh,
            'node:20-alpine',
            '/var/lib/talksasa/containers/user-1-service-1/app',
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_OPTIONS=--max-old-space-size=4096 NODE_ENV=production /usr/local/bin/npm run build',
            '/app',
            900
        );
    }

    #[Test]
    public function it_runs_post_pull_commands_in_one_off_containers(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->once())
            ->method('exec')
            ->with($this->callback(fn (string $command): bool => str_contains($command, 'docker compose run --rm -T')
                && str_contains($command, 'npm install')))
            ->willReturn('');

        $service->runOneOffInContainer(
            $ssh,
            '/var/lib/talksasa/containers/user-1-service-1',
            'user-1-service-1-nodejs',
            'npm install',
            '/app',
            120
        );
    }
}
