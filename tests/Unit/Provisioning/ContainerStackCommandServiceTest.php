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
    public function it_allows_node_bin_chmod_commands_without_shell_metacharacters(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertTrue($service->isSafeCommand("test -d '/app/node_modules/.bin'"));
        $this->assertTrue($service->isSafeCommand("find '/app/node_modules/.bin' -type f -exec chmod u+x {} +"));
        $this->assertTrue($service->isSafeCommand("find '/app/node_modules/next/dist/bin' -type f -exec chmod u+x {} +"));
        $this->assertFalse($service->isSafeCommand(
            "find '/app/node_modules/.bin' '/app/node_modules/next/dist/bin' -type f -exec chmod u+x {} + 2>/dev/null || true"
        ));
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
        $this->assertSame('/root/.ollama', $service->resolveWorkDir((object) [
            'slug' => 'ollama',
            'volume_paths' => ['ollama_data' => '/root/.ollama'],
        ]));
        $this->assertSame('/root/.ollama', $service->resolveWorkDir((object) [
            'slug' => 'ollama',
            'volume_paths' => [],
        ]));
    }

    #[Test]
    public function it_rejects_unsafe_container_commands(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertFalse($service->isSafeCommand('npm install; rm -rf /'));
        $this->assertFalse($service->isSafeCommand("npm install\nrm -rf /"));
        $this->assertFalse($service->isSafeCommand("npm install\t--omit=dev"));
        $this->assertTrue($service->isSafeCommand('bundle install --without development test'));
    }

    #[Test]
    public function cron_style_exec_can_run_as_restricted_user_without_ssh_replay(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->once())
            ->method('exec')
            ->with(
                $this->callback(fn (string $command): bool => str_contains(
                    $command,
                    "docker compose exec -u 'www-data' -T -w '/app/backend' 'backend'"
                )),
                60,
                false,
            )
            ->willReturn('ok');

        $output = $service->execInContainer(
            $ssh,
            '/var/lib/talksasa/containers/app',
            'backend',
            'php artisan schedule:run',
            '/app/backend',
            60,
            'www-data',
            retry: false,
        );

        $this->assertSame('ok', $output);
    }

    #[Test]
    public function it_allows_npm_build_commands(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertFalse($service->isLongRunningCommand('npm run build'));
        $this->assertTrue($service->isSafeCommand('npm run build'));
        $this->assertTrue($service->isSafeCommand('npm prune --omit=dev'));
        $this->assertTrue($service->isSafeCommand('rm -rf node_modules'));
        $this->assertTrue($service->isSafeCommand('env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_ENV=development /usr/local/bin/npm install --production=false --include=dev --legacy-peer-deps --no-audit --no-fund'));
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
                && str_contains($command, '/usr/local/bin/npm install --production=false --include=dev --legacy-peer-deps')))
            ->willReturn('');

        $service->runOneOffInContainer(
            $ssh,
            '/var/lib/talksasa/containers/user-1-service-1',
            'user-1-service-1-nodejs',
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_ENV=development /usr/local/bin/npm install --production=false --include=dev --legacy-peer-deps --no-audit --no-fund',
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
            ->with($this->callback(fn (string $command): bool => str_contains($command, 'docker run --rm --network ')
                && str_contains($command, 'talksasa-net')
                && str_contains($command, 'node:20-alpine')
                && str_contains($command, 'apk add --no-cache openssl libc6-compat')
                && str_contains($command, 'node ./node_modules/next/dist/bin/next build')
                && str_ends_with(trim($command), '2>&1')))
            ->willReturn('');

        $service->runUnlimitedMemoryNodeCommand(
            $ssh,
            'node:20-alpine',
            '/var/lib/talksasa/containers/user-1-service-1/app',
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_OPTIONS=--max-old-space-size=4096 NODE_ENV=production node ./node_modules/next/dist/bin/next build',
            '/app',
            900
        );
    }

    #[Test]
    public function it_runs_production_build_installs_with_docker_run(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->once())
            ->method('exec')
            ->with($this->callback(fn (string $command): bool => str_contains($command, 'docker run --rm --network ')
                && str_contains($command, 'talksasa-net')
                && str_contains($command, 'node:20-alpine')
                && str_contains($command, '/usr/local/bin/npm ci --include=dev --legacy-peer-deps')
                && str_ends_with(trim($command), '2>&1')))
            ->willReturn('');

        $service->runUnlimitedMemoryNodeCommand(
            $ssh,
            'node:20-alpine',
            '/var/lib/talksasa/containers/user-1-service-1/app',
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_ENV=development /usr/local/bin/npm ci --include=dev --legacy-peer-deps --no-audit --no-fund',
            '/app',
            600
        );
    }

    #[Test]
    public function it_skips_alpine_openssl_prefix_on_debian_node_images(): void
    {
        $service = new ContainerStackCommandService;
        $this->assertSame('', $service->alpineOpensslEnsurePrefix('node:20-bookworm-slim'));
        $this->assertStringContainsString('apk add --no-cache openssl', $service->alpineOpensslEnsurePrefix('node:20-alpine'));
    }

    #[Test]
    public function it_treats_npm_peer_conflicts_as_recoverable_ci_errors(): void
    {
        $service = new ContainerStackCommandService;
        $error = new \RuntimeException(
            'npm error code ERESOLVE npm error Could not resolve dependency: peer next@"^15.0.0" from @sentry/nextjs'
        );

        $this->assertTrue($service->isNpmCiRecoverableError($error));
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

    #[Test]
    public function it_detects_npm_ci_lockfile_out_of_sync_errors(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertTrue($service->isNpmLockfileOutOfSyncError(new \RuntimeException(
            'npm error `npm ci` can only install packages when your package.json and package-lock.json or npm-shrinkwrap.json are in sync. Please update your lock file with `npm install` before continuing.'
        )));
        $this->assertTrue($service->isNpmLockfileOutOfSyncError(new \RuntimeException(
            "npm error code EUSAGE\nnpm error\nnpm ci\nnpm error Missing: vitest@3.2.7 from lock file"
        )));
        $this->assertFalse($service->isNpmLockfileOutOfSyncError(new \RuntimeException(
            'npm ERR! network request failed'
        )));
    }

    #[Test]
    public function it_falls_back_to_npm_install_when_npm_ci_lockfile_is_out_of_sync(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')
            ->willReturnCallback(function (string $command): string {
                return str_contains($command, 'package-lock.json') ? 'yes' : 'no';
            });

        $commands = [];
        $method = (new \ReflectionClass(ContainerStackCommandService::class))
            ->getMethod('installNodeDependenciesPreferringLockfile');
        $method->setAccessible(true);
        $method->invoke(
            $service,
            $ssh,
            '/opt/talksasa/containers/user-1-service-1/app',
            true,
            function (string $command) use (&$commands): void {
                $commands[] = $command;
                if (str_contains($command, 'npm ci') || str_contains($command, '/usr/local/bin/npm ci')) {
                    throw new \RuntimeException(
                        'npm error `npm ci` can only install packages when your package.json and package-lock.json are in sync. Missing: vitest@3.2.7 from lock file'
                    );
                }
            }
        );

        $this->assertCount(2, $commands);
        $this->assertTrue(str_contains($commands[0], 'npm ci') || str_contains($commands[0], '/usr/local/bin/npm ci'));
        $this->assertTrue(str_contains($commands[1], 'npm install') || str_contains($commands[1], '/usr/local/bin/npm install'));
    }

    #[Test]
    public function it_detects_pnpm_and_yarn_from_lockfiles(): void
    {
        $service = new ContainerStackCommandService;

        $pnpmSsh = $this->createMock(SSHService::class);
        $pnpmSsh->method('exec')->willReturnCallback(
            fn (string $command): string => str_contains($command, 'pnpm-lock.yaml') ? 'yes' : 'no'
        );
        $this->assertSame('pnpm', $service->detectHostNodePackageManager(
            $pnpmSsh,
            '/opt/talksasa/containers/user-1-service-1/app'
        ));

        $yarnSsh = $this->createMock(SSHService::class);
        $yarnSsh->method('exec')->willReturnCallback(
            fn (string $command): string => str_contains($command, 'yarn.lock') ? 'yes' : 'no'
        );
        $this->assertSame('yarn', $service->detectHostNodePackageManager(
            $yarnSsh,
            '/opt/talksasa/containers/user-1-service-1/app'
        ));

        $bothSsh = $this->createMock(SSHService::class);
        $bothSsh->method('exec')->willReturnCallback(
            fn (string $command): string => (
                str_contains($command, 'package-lock.json') || str_contains($command, 'pnpm-lock.yaml')
            ) ? 'yes' : 'no'
        );
        $this->assertSame('npm', $service->detectHostNodePackageManager(
            $bothSsh,
            '/opt/talksasa/containers/user-1-service-1/app'
        ));

        $declaredSsh = $this->createMock(SSHService::class);
        $declaredSsh->method('exec')->willReturn('no');
        $this->assertSame('pnpm', $service->detectHostNodePackageManager(
            $declaredSsh,
            '/opt/talksasa/containers/user-1-service-1/app',
            '{"packageManager":"pnpm@9.15.4"}'
        ));
    }

    #[Test]
    public function it_installs_pnpm_projects_with_corepack_instead_of_rejecting_them(): void
    {
        $service = new ContainerStackCommandService;
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(
            fn (string $command): string => str_contains($command, 'pnpm-lock.yaml') ? 'yes' : 'no'
        );

        $commands = [];
        $method = (new \ReflectionClass(ContainerStackCommandService::class))
            ->getMethod('installNodeDependenciesPreferringLockfile');
        $method->setAccessible(true);
        $method->invoke(
            $service,
            $ssh,
            '/opt/talksasa/containers/user-1-service-1/app',
            true,
            function (string $command) use (&$commands): void {
                $commands[] = $command;
            }
        );

        $this->assertNotEmpty($commands);
        $this->assertStringContainsString('corepack pnpm', $commands[0]);
        $this->assertStringContainsString('--frozen-lockfile', $commands[0]);
        $this->assertTrue($service->isSafeCommand($commands[0]));
    }

    #[Test]
    public function it_treats_pnpm_outdated_lockfile_as_recoverable(): void
    {
        $service = new ContainerStackCommandService;

        $this->assertTrue($service->isNpmCiRecoverableError(new \RuntimeException(
            'ERR_PNPM_OUTDATED_LOCKFILE Cannot install with frozen-lockfile because pnpm-lock.yaml is not up to date'
        )));
    }
}
