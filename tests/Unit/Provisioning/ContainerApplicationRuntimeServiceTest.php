<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerApplicationRuntimeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerApplicationRuntimeServiceTest extends TestCase
{
    private ContainerApplicationRuntimeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContainerApplicationRuntimeService;
    }

    #[Test]
    public function it_detects_node_start_script_from_package_json(): void
    {
        $runtime = $this->service->detectNodeFromContents(
            null,
            '{"scripts":{"start":"node server.js"}}',
            false,
            false,
            false,
            3000
        );

        $this->assertSame('package.json', $runtime->source);
        $this->assertSame('npm start', $runtime->label);
        $this->assertStringContainsString('npm install --omit=dev', $runtime->command[2]);
        $this->assertStringContainsString('exec npm start', $runtime->command[2]);
    }

    #[Test]
    public function it_detects_node_procfile_and_entrypoints(): void
    {
        $runtime = $this->service->detectNodeFromContents(
            'node server.js',
            null,
            false,
            false,
            false,
            3000
        );
        $this->assertSame('procfile', $runtime->source);

        $runtime = $this->service->detectNodeFromContents(
            null,
            null,
            true,
            false,
            false,
            3000
        );
        $this->assertSame('entrypoint', $runtime->source);
        $this->assertStringContainsString('node server.js', $runtime->command[2]);
    }

    #[Test]
    public function it_detects_rails_and_rack_applications(): void
    {
        $runtime = $this->service->detectRubyFromContents(null, true, false, 3000);
        $this->assertSame('rails', $runtime->source);
        $this->assertStringContainsString('bundle exec rails server', $runtime->command[2]);

        $runtime = $this->service->detectRubyFromContents(null, false, true, 3000);
        $this->assertSame('rack', $runtime->source);
        $this->assertStringContainsString('bundle exec rackup config.ru', $runtime->command[2]);
    }

    #[Test]
    public function it_detects_python_django_gunicorn_and_uvicorn(): void
    {
        $runtime = $this->service->detectPythonFromContents(null, null, null, true, false, false, 8000);
        $this->assertSame('django', $runtime->source);

        $wsgi = "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'config.settings')\napplication = get_wsgi_application()";
        $runtime = $this->service->detectPythonFromContents(
            null,
            "gunicorn\n",
            $wsgi,
            false,
            false,
            false,
            8000
        );
        $this->assertSame('gunicorn', $runtime->source);
        $this->assertStringContainsString('gunicorn config.wsgi:application', $runtime->command[2]);

        $runtime = $this->service->detectPythonFromContents(
            null,
            "uvicorn\n",
            null,
            false,
            true,
            false,
            8000
        );
        $this->assertSame('uvicorn', $runtime->source);
    }

    #[Test]
    public function it_uses_placeholder_http_servers_when_no_app_is_present(): void
    {
        $runtime = $this->service->fallbackRuntime('nodejs', 3000);
        $this->assertSame('fallback', $runtime->source);
        $this->assertStringContainsString('createServer', $runtime->command[2]);

        $runtime = $this->service->fallbackRuntime('python', 8000);
        $this->assertStringContainsString('http.server', $runtime->command[2]);
    }

    #[Test]
    public function it_rejects_unsafe_start_commands(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->sanitizeInnerCommand('npm start; rm -rf /');
    }

    #[Test]
    public function it_detects_next_js_as_requiring_production_build(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
            ],
            'dependencies' => [
                'next' => '14.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue($this->service->packageJsonRequiresProductionBuild($packageJson));
        $this->assertSame('.next', $this->service->packageJsonBuildOutputDir($packageJson));
    }

    #[Test]
    public function it_skips_build_for_plain_express_apps(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'start' => 'node server.js',
            ],
            'dependencies' => [
                'express' => '^4.18.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertFalse($this->service->packageJsonRequiresProductionBuild($packageJson));
        $this->assertStringContainsString('npm install --omit=dev', $this->service->nodeBootstrap($packageJson));
        $this->assertStringNotContainsString('npm run build', $this->service->nodeBootstrap($packageJson));
    }

    #[Test]
    public function it_ignores_scripts_on_omit_dev_install_when_postinstall_runs_vite(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'postinstall' => 'vite build',
                'build' => 'vite build',
                'start' => 'vite preview --host 0.0.0.0 --port 3000',
            ],
            'devDependencies' => [
                'vite' => '^5.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue($this->service->packageJsonPostinstallRequiresBuildTools($packageJson));
        $this->assertSame(
            'npm install --omit=dev --legacy-peer-deps --ignore-scripts',
            $this->service->npmOmitDevInstallCommand($packageJson)
        );
        // Vite preview needs its dev tree, so the boot install keeps dev deps but still
        // skips the postinstall build.
        $this->assertStringContainsString(
            '--include=dev --legacy-peer-deps --no-audit --no-fund --ignore-scripts',
            $this->service->nodeBootstrap($packageJson)
        );
    }

    #[Test]
    public function it_preserves_vite_middleware_start_and_keeps_vite_installed(): void
    {
        $packageJson = json_encode([
            'name' => 'react-example',
            'scripts' => [
                'build' => 'vite build',
                'start' => "NODE_OPTIONS='--no-warnings' tsx server.ts",
            ],
            'devDependencies' => [
                'vite' => '^6.0.0',
                'tsx' => '^4.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $packageJson,
            false,
            false,
            false,
            3000
        );

        $this->assertSame('vite', $runtime->source);
        $this->assertSame('Vite app server', $runtime->label);
        $this->assertStringContainsString('npm start', $runtime->command[2]);
        $this->assertStringNotContainsString('vite preview', $runtime->command[2]);
        $this->assertFalse(
            $this->service->shouldRewriteStartToVitePreview(
                "NODE_OPTIONS='--no-warnings' tsx server.ts",
                $packageJson
            )
        );
        $this->assertTrue($this->service->productionStartRequiresVite($packageJson));
        $this->assertStringNotContainsString('prune --omit=dev', $runtime->command[2]);
        $this->assertStringNotContainsString('npm install --omit=dev', $runtime->command[2]);
    }

    #[Test]
    public function it_preserves_vite_bundled_dist_server_and_keeps_vite_installed(): void
    {
        $packageJson = json_encode([
            'name' => 'react-example',
            'scripts' => [
                'build' => 'vite build',
                'start' => 'node dist/server.cjs',
            ],
            'devDependencies' => [
                'vite' => '^6.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $packageJson,
            false,
            false,
            false,
            3000
        );

        $this->assertSame('vite', $runtime->source);
        $this->assertSame('Vite app server', $runtime->label);
        $this->assertTrue($this->service->commandLooksLikeViteBundledCustomServer('node dist/server.cjs'));
        $this->assertFalse($this->service->shouldRewriteStartToVitePreview('node dist/server.cjs', $packageJson));
        $this->assertStringContainsString('npm start', $runtime->command[2]);
        $this->assertStringNotContainsString('vite preview', $runtime->command[2]);
        $this->assertTrue($this->service->productionStartRequiresVite($packageJson));
        $this->assertStringNotContainsString('npm install --omit=dev', $runtime->command[2]);
        $this->assertStringNotContainsString('prune --omit=dev', $runtime->command[2]);
    }

    #[Test]
    public function it_rewrites_bare_vite_cli_to_production_preview(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'vite build',
                'start' => 'vite --host 0.0.0.0',
            ],
            'devDependencies' => [
                'vite' => '^5.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $packageJson,
            false,
            false,
            false,
            3000
        );

        $this->assertSame('vite', $runtime->source);
        $this->assertTrue($this->service->shouldRewriteStartToVitePreview('vite --host 0.0.0.0', $packageJson));
        $this->assertStringContainsString(
            'npx vite preview --host 0.0.0.0 --port ${PORT:-3000}',
            $runtime->command[2]
        );
    }

    #[Test]
    public function it_does_not_rewrite_plain_express_dist_entry_without_vite(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'tsc',
                'start' => 'node dist/index.js',
            ],
            'dependencies' => [
                'express' => '^4.18.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $packageJson,
            false,
            false,
            false,
            3000
        );

        $this->assertNotSame('vite', $runtime->source);
        $this->assertStringContainsString('npm start', $runtime->command[2]);
        $this->assertFalse(
            $this->service->shouldRewriteStartToVitePreview('node dist/index.js', $packageJson)
        );
    }

    #[Test]
    public function it_normalizes_existing_vite_preview_start_commands(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'vite build',
                'start' => 'vite preview --port 5173',
            ],
            'devDependencies' => [
                'vite' => '^5.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $command = $this->service->platformNodeListenCommand(
            'vite preview --port 5173',
            3000,
            $packageJson
        );

        $this->assertSame(
            'npx vite preview --host 0.0.0.0 --port ${PORT:-3000} --strictPort',
            $command
        );
    }

    #[Test]
    public function it_serves_an_expo_web_export_instead_of_metro(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'start' => 'expo start',
            ],
            'dependencies' => [
                'expo' => '^54.0',
                'react-native' => '^0.81',
            ],
        ], JSON_THROW_ON_ERROR);

        $command = $this->service->platformNodeListenCommand(
            'expo start',
            3000,
            $packageJson
        );

        $this->assertSame(
            'npx --yes serve@14 dist -s --listen tcp://0.0.0.0:${PORT:-3000}',
            $command
        );
        $this->assertTrue($this->service->packageJsonNeedsExpoWebExport($packageJson));
        $this->assertTrue($this->service->packageJsonRequiresProductionBuild($packageJson));
        $this->assertStringContainsString(
            'npx --yes expo export --platform web --output-dir dist',
            $this->service->nodeProductionBuildShellCommand($packageJson, null, 'npm'),
        );
        $this->assertStringContainsString(
            'npx --yes expo export --platform web --output-dir dist',
            $this->service->nodeBootstrap($packageJson),
        );
        $this->assertTrue($this->service->isAllowedNodeBuildEnvKey('EXPO_PUBLIC_API_URL'));
        $this->assertStringNotContainsString('&&', $this->service->nodeProductionBuildShellCommand($packageJson, null, 'npm', 'apps/mobile'));
        $this->assertSame('apps/mobile', $this->service->expoWebExportRelativeRoot($packageJson, 'apps/mobile'));
        $this->assertSame('', $this->service->expoWebExportRelativeRoot($packageJson, ''));
        $this->assertSame('', $this->service->expoWebExportRelativeRoot(
            json_encode(['dependencies' => ['next' => '14.0.0']], JSON_THROW_ON_ERROR),
            'apps/api',
        ));
        $this->assertTrue($this->service->packageJsonUsesDefaultExpoAppEntry($packageJson));
        $this->assertNull($this->service->packageJsonWithExpoRouterWebMain($packageJson));

        $routerJson = json_encode([
            'scripts' => ['start' => 'expo start'],
            'dependencies' => [
                'expo' => '^54.0',
                'expo-router' => '~5.0',
            ],
        ], JSON_THROW_ON_ERROR);
        $rewritten = $this->service->packageJsonWithExpoRouterWebMain($routerJson);
        $this->assertNotNull($rewritten);
        $this->assertSame('expo-router/entry', json_decode($rewritten, true)['main']);
        $this->assertNull($this->service->packageJsonWithExpoRouterWebMain(
            json_encode([
                'main' => 'expo-router/entry',
                'dependencies' => ['expo-router' => '~5.0'],
            ], JSON_THROW_ON_ERROR)
        ));
    }

    #[Test]
    public function it_builds_next_js_on_container_start_when_artifact_is_missing(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
            ],
            'dependencies' => [
                'next' => '14.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = new ContainerApplicationRuntimeService;
        $bootstrap = $runtime->nodeBootstrap($packageJson);

        $this->assertStringContainsString('apk add --no-cache openssl libc6-compat', $bootstrap);
        $this->assertStringContainsString('env -i HOME=/tmp', $bootstrap);
        $this->assertStringContainsString('/usr/local/bin/npm install --production=false --include=dev', $bootstrap);
        $this->assertStringContainsString(
            '{ [ ! -f .talksasa/prepare-build.cjs ] || node .talksasa/prepare-build.cjs; }',
            $bootstrap
        );
        $this->assertStringContainsString('node ./node_modules/next/dist/bin/next build', $bootstrap);
        $this->assertStringContainsString('.next/BUILD_ID', $bootstrap);
    }

    #[Test]
    public function production_node_runtime_starts_the_validated_release_without_installing_or_building(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
            ],
            'dependencies' => ['next' => '14.0.0'],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $packageJson,
            false,
            false,
            false,
            3000,
            includeBootstrap: false,
        );
        $command = $runtime->command[2];

        $this->assertStringContainsString('exec npx next start', $command);
        $this->assertStringNotContainsString('npm install', $command);
        $this->assertStringNotContainsString('next build', $command);
        $this->assertStringNotContainsString('prepare-build.cjs', $command);
    }

    #[Test]
    public function it_lists_node_build_artifact_dirs_for_next_and_nuxt(): void
    {
        $next = json_encode(['dependencies' => ['next' => '14.0.0']], JSON_THROW_ON_ERROR);
        $nuxt = json_encode(['dependencies' => ['nuxt' => '3.0.0']], JSON_THROW_ON_ERROR);

        $this->assertSame(['.next'], $this->service->nodeBuildArtifactDirs($next));
        $this->assertContains('.nuxt', $this->service->nodeBuildArtifactDirs($nuxt));
        $this->assertContains('.output', $this->service->nodeBuildArtifactDirs($nuxt));
    }

    #[Test]
    public function it_rebuilds_next_js_when_artifact_directory_exists_without_build_id(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
            ],
            'dependencies' => [
                'next' => '14.0.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $bootstrap = $this->service->nodeBootstrap($packageJson);

        $this->assertStringContainsString('[ ! -f .next/BUILD_ID ]', $bootstrap);
        $this->assertStringNotContainsString('[ ! -d .next ]', $bootstrap);
    }

    #[Test]
    public function it_builds_npm_install_and_build_shell_commands_with_clean_env(): void
    {
        $runtime = new ContainerApplicationRuntimeService;

        $this->assertStringContainsString(
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_omit= NODE_ENV=development /usr/local/bin/npm install --production=false --include=dev --legacy-peer-deps --no-audit --no-fund',
            $runtime->npmInstallShellCommand()
        );
        $npmBuild = $runtime->npmBuildShellCommand(1000);
        $this->assertStringContainsString('NODE_OPTIONS=--max-old-space-size=650', $npmBuild);
        $this->assertStringContainsString('COREPACK_HOME=/tmp/.corepack', $npmBuild);
        $this->assertStringContainsString('TURBO_TELEMETRY_DISABLED=1', $npmBuild);
        $this->assertStringContainsString('/usr/local/bin/npm run build', $npmBuild);
        $nextPackage = json_encode([
            'dependencies' => ['next' => '14.2.35'],
        ], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            'node ./node_modules/next/dist/bin/next build',
            $runtime->npmBuildShellCommand(null, true, $nextPackage)
        );
        $this->assertStringContainsString(
            'NODE_OPTIONS=--max-old-space-size=4096',
            $runtime->npmBuildShellCommand(null, true, $nextPackage)
        );
        $this->assertStringContainsString(
            '/usr/local/bin/npm ci --include=dev --legacy-peer-deps --no-audit --no-fund',
            $runtime->npmCiShellCommand()
        );
        $this->assertStringContainsString(
            '/usr/local/bin/npm prune --omit=dev --legacy-peer-deps',
            $runtime->npmPruneShellCommand()
        );
        $this->assertSame(650, $runtime->nodeBuildHeapLimitMb(1000));
        $this->assertStringContainsString(
            'tailwindcss',
            $runtime->npmInstallDevPackagesShellCommand(json_encode([
                'devDependencies' => [
                    'tailwindcss' => '^3.4.1',
                    'typescript' => '^5',
                ],
            ], JSON_THROW_ON_ERROR))
        );
    }

    #[Test]
    public function it_resolves_next_integrity_marker_for_installed_frameworks(): void
    {
        $next = json_encode([
            'dependencies' => ['next' => '14.2.35'],
        ], JSON_THROW_ON_ERROR);
        $nuxt = json_encode([
            'dependencies' => ['nuxt' => '3.0.0'],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'node_modules/next/package.json',
                'node_modules/next/dist/bin/next',
                'node_modules/react/package.json',
                'node_modules/react/index.js',
                'node_modules/react-dom/package.json',
            ],
            $this->service->nodeIntegrityMarkerRelativePaths($next)
        );
        $this->assertSame(
            'node_modules/next/package.json',
            $this->service->nodeIntegrityMarkerRelativePath($next)
        );
        $this->assertSame(['node_modules/nuxt/package.json'], $this->service->nodeIntegrityMarkerRelativePaths($nuxt));
        $this->assertSame('node_modules/nuxt/package.json', $this->service->nodeIntegrityMarkerRelativePath($nuxt));
        $vite = json_encode([
            'dependencies' => ['vite' => '5.0.0'],
        ], JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                'node_modules/vite/package.json',
                'node_modules/vite/bin/vite.js',
            ],
            $this->service->nodeIntegrityMarkerRelativePaths($vite)
        );
        $this->assertSame([], $this->service->nodeIntegrityMarkerRelativePaths('{"dependencies":{"express":"^4"}}'));
        $this->assertNull($this->service->nodeIntegrityMarkerRelativePath('{"dependencies":{"express":"^4"}}'));
        $this->assertTrue($this->service->packageJsonUsesNext($next));
        $this->assertStringContainsString('install react react-dom', $this->service->npmInstallNextPeersShellCommand());

        $deployment = new ContainerDeployment([
            'env_values' => [
                'NEXT_PUBLIC_API_URL' => 'https://api.example.com',
                'SECRET_KEY' => 'should-not-appear',
                'VITE_APP_NAME' => 'Demo',
            ],
        ]);
        $buildEnv = $this->service->collectNodeBuildEnvFromDeployment($deployment);
        $this->assertSame('https://api.example.com', $buildEnv['NEXT_PUBLIC_API_URL']);
        $this->assertSame('Demo', $buildEnv['VITE_APP_NAME']);
        $this->assertArrayNotHasKey('SECRET_KEY', $buildEnv);
    }

    #[Test]
    public function it_includes_production_build_bootstrap_for_next_start(): void
    {
        $runtime = $this->service->detectNodeFromContents(
            null,
            '{"scripts":{"build":"next build","start":"next start"},"dependencies":{"next":"14.0.0"}}',
            false,
            false,
            false,
            3000
        );

        $this->assertSame('next', $runtime->source);
        $this->assertStringContainsString('node ./node_modules/next/dist/bin/next build', $runtime->command[2]);
        $this->assertStringContainsString('npx next start -H 0.0.0.0 -p ${PORT:-3000}', $runtime->command[2]);
        $this->assertStringNotContainsString('exec npm start', $runtime->command[2]);
    }

    #[Test]
    public function it_rewrites_hardcoded_next_start_port_to_platform_port(): void
    {
        $runtime = $this->service->detectNodeFromContents(
            null,
            '{"scripts":{"start":"next start -p 3001"},"dependencies":{"next":"14.0.0"}}',
            false,
            false,
            false,
            3000
        );

        $this->assertSame('next', $runtime->source);
        $this->assertStringContainsString('npx next start -H 0.0.0.0 -p ${PORT:-3000}', $runtime->command[2]);
        $this->assertStringNotContainsString('3001', $runtime->command[2]);
    }

    #[Test]
    public function it_rewrites_procfile_next_start_to_platform_port(): void
    {
        $runtime = $this->service->detectNodeFromContents(
            'next start -p 3001',
            '{"dependencies":{"next":"14.0.0"}}',
            false,
            false,
            false,
            3000
        );

        $this->assertSame('next', $runtime->source);
        $this->assertStringContainsString('npx next start -H 0.0.0.0 -p ${PORT:-3000}', $runtime->command[2]);
    }

    #[Test]
    public function it_detects_go_module_and_cmd_server_entrypoints(): void
    {
        $module = $this->service->detectGoFromContents(null, true, true, false, 8080);
        $this->assertSame('entrypoint', $module->source);
        $this->assertStringContainsString('go run .', $module->command[2]);
        $this->assertStringContainsString('go mod download', $module->command[2]);

        $cmd = $this->service->detectGoFromContents(null, true, false, true, 8080);
        $this->assertStringContainsString('go run ./cmd/server', $cmd->command[2]);

        $this->assertTrue($this->service->supportsTemplate('go'));
    }

    #[Test]
    public function it_builds_corepack_pnpm_and_yarn_install_commands(): void
    {
        $pnpm = $this->service->pnpmInstallShellCommand(true, true);
        $this->assertStringContainsString('/usr/local/bin/corepack pnpm install --frozen-lockfile', $pnpm);
        $this->assertStringContainsString('COREPACK_HOME=/tmp/.corepack', $pnpm);
        $this->assertStringContainsString('COREPACK_ENABLE_STRICT=0', $pnpm);

        $npx = $this->service->pnpmInstallShellCommand(true, true, viaNpx: true);
        $this->assertStringContainsString('/usr/local/bin/npx --yes pnpm@9 install --frozen-lockfile', $npx);

        $yarn = $this->service->yarnInstallShellCommand(true, 'immutable');
        $this->assertStringContainsString('/usr/local/bin/corepack yarn install --immutable', $yarn);
    }

    #[Test]
    public function it_builds_turbo_monorepos_with_the_declared_package_manager(): void
    {
        $pnpmTurbo = json_encode([
            'packageManager' => 'pnpm@9.15.4',
            'scripts' => [
                'build' => 'turbo run build',
            ],
            'devDependencies' => [
                'turbo' => '2.9.18',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('pnpm', $this->service->detectNodePackageManagerFromPackageJson($pnpmTurbo));
        $this->assertTrue($this->service->packageJsonUsesTurbo($pnpmTurbo));
        $this->assertTrue($this->service->packageJsonRequiresProductionBuild($pnpmTurbo));

        $build = $this->service->npmBuildShellCommand(null, true, $pnpmTurbo);
        $this->assertStringContainsString('/usr/local/bin/corepack pnpm run build', $build);
        $this->assertStringContainsString('COREPACK_HOME=/tmp/.corepack', $build);
        $this->assertStringNotContainsString('node ./node_modules/next/dist/bin/next build', $build);

        $api = json_encode([
            'name' => '@sameplan/api',
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'dependencies' => ['next' => '15.5.25'],
        ], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            '/usr/local/bin/npm --prefix apps/api run build',
            $this->service->nodeProductionBuildShellCommand($api, $pnpmTurbo, 'npm', 'apps/api'),
        );
        $this->assertStringNotContainsString(
            'turbo run build',
            $this->service->nodeProductionBuildShellCommand($api, $pnpmTurbo, 'npm', 'apps/api'),
        );

        $nextPlusTurbo = json_encode([
            'packageManager' => 'pnpm@9.15.4',
            'scripts' => [
                'build' => 'turbo run build',
            ],
            'dependencies' => [
                'next' => '14.2.35',
            ],
            'devDependencies' => [
                'turbo' => '2.9.18',
            ],
        ], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            '/usr/local/bin/corepack pnpm run build',
            $this->service->npmBuildShellCommand(null, true, $nextPlusTurbo)
        );
    }

    #[Test]
    public function it_infers_vite_preview_when_package_json_has_no_start_script(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'dev' => 'vite',
                'build' => 'vite build',
                'preview' => 'vite preview',
            ],
            'devDependencies' => [
                'vite' => '5.4.0',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(null, $packageJson, false, false, false, 3000);

        $this->assertNotSame('fallback', $runtime->source);
        $this->assertStringContainsString('vite preview', $runtime->command[2]);
        $this->assertStringContainsString('cd /app &&', $runtime->command[2]);
    }

    #[Test]
    public function it_infers_next_start_when_package_json_has_no_start_script(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'dev' => 'next dev',
                'build' => 'next build',
            ],
            'dependencies' => [
                'next' => '14.2.35',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(null, $packageJson, false, false, false, 3000);

        $this->assertSame('next', $runtime->source);
        $this->assertStringContainsString('npx next start', $runtime->command[2]);
    }

    #[Test]
    public function it_starts_nested_node_apps_from_their_package_root(): void
    {
        $packageJson = json_encode([
            'scripts' => [
                'start' => 'node server.js',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $packageJson,
            false,
            false,
            false,
            3000,
            '/app/backend'
        );

        $this->assertSame('/app/backend', $runtime->containerWorkdir);
        $this->assertStringContainsString('cd /app/backend &&', $runtime->command[2]);
        $this->assertStringContainsString('exec npm start', $runtime->command[2]);
    }

    #[Test]
    public function it_rejects_unsafe_node_workdirs(): void
    {
        $this->assertSame('/app', $this->service->sanitizeContainerWorkdir('/etc/passwd'));
        $this->assertSame('/app', $this->service->sanitizeContainerWorkdir('app/foo/../../etc'));
        $this->assertSame('/app/apps/web', $this->service->sanitizeContainerWorkdir('apps/web'));
    }

    #[Test]
    public function workspace_root_without_start_is_not_a_direct_app(): void
    {
        $root = json_encode([
            'private' => true,
            'packageManager' => 'pnpm@9.15.4',
            'workspaces' => ['apps/*'],
            'scripts' => [
                'dev' => 'turbo run dev',
                'build' => 'turbo run build',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue($this->service->packageJsonIsWorkspaceRoot($root));
        $this->assertFalse($this->service->packageJsonHasDirectStart($root));
        $this->assertFalse($this->service->packageJsonLooksRunnable($root));
    }

    #[Test]
    public function workspace_protocol_selects_pnpm_not_npm(): void
    {
        $app = json_encode([
            'name' => 'web',
            'dependencies' => [
                'next' => '14.2.35',
                '@repo/ui' => 'workspace:*',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue($this->service->packageJsonUsesWorkspaceProtocol($app));
        $this->assertSame('pnpm', $this->service->detectNodePackageManagerFromPackageJson($app));
        $this->assertTrue($this->service->packageJsonIndicatesWorkspaceLayout(
            '{"private":true,"packageManager":"pnpm@9.15.4"}',
            $app,
            'apps/web'
        ));
    }

    #[Test]
    public function nested_workspace_app_installs_from_repo_root_with_pnpm(): void
    {
        $app = json_encode([
            'name' => 'web',
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
            ],
            'dependencies' => [
                'next' => '14.2.35',
                '@repo/ui' => 'workspace:*',
            ],
        ], JSON_THROW_ON_ERROR);
        $root = json_encode([
            'private' => true,
            'packageManager' => 'pnpm@9.15.4',
            'scripts' => [
                'build' => 'turbo run build',
            ],
            'devDependencies' => [
                'turbo' => '2.9.18',
            ],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $app,
            false,
            false,
            false,
            3000,
            '/app/apps/web',
            $root,
            '/app'
        );

        $command = $runtime->command[2];
        $this->assertSame('/app/apps/web', $runtime->containerWorkdir);
        $this->assertSame('next', $runtime->source);
        $this->assertStringContainsString('cd /app &&', $command);
        $this->assertStringContainsString('cd /app/apps/web && exec npx next start', $command);
        $this->assertStringContainsString('/usr/local/bin/corepack pnpm install', $command);
        $this->assertStringContainsString('[ ! -f apps/web/.next/BUILD_ID ]', $command);
        $this->assertStringContainsString('/usr/local/bin/corepack pnpm --dir apps/web run build', $command);
        $this->assertStringNotContainsString('npm install --omit=dev', $command);
        $this->assertStringNotContainsString('EUNSUPPORTEDPROTOCOL', $command);
        $this->assertStringNotContainsString('prune --prod', $command);
    }

    #[Test]
    public function workspace_next_without_turbo_builds_the_package_dir(): void
    {
        $app = json_encode([
            'scripts' => [
                'build' => 'next build',
                'start' => 'next start',
            ],
            'dependencies' => [
                'next' => '14.2.35',
                '@repo/ui' => 'workspace:*',
            ],
        ], JSON_THROW_ON_ERROR);

        $bootstrap = $this->service->nodeBootstrap($app, '{"private":true}', 'apps/web');

        $this->assertStringContainsString('/usr/local/bin/corepack pnpm --dir apps/web run build', $bootstrap);
        $this->assertStringContainsString('[ ! -f apps/web/.next/BUILD_ID ]', $bootstrap);
        $this->assertStringNotContainsString('npm install --omit=dev', $bootstrap);
    }

    #[Test]
    public function declared_npm_package_manager_is_not_overridden_to_pnpm(): void
    {
        $root = json_encode([
            'private' => true,
            'packageManager' => 'npm@10.9.2',
            'workspaces' => ['apps/*'],
            'scripts' => [
                'build' => 'npm run build --workspaces',
            ],
        ], JSON_THROW_ON_ERROR);
        $app = json_encode([
            'name' => 'web',
            'scripts' => [
                'start' => 'next start',
                'build' => 'next build',
            ],
            'dependencies' => [
                'next' => '14.2.35',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('npm', $this->service->declaredNodePackageManagerFromPackageJson($root));
        $this->assertSame('npm', $this->service->resolveNodePackageManager($app, $root));
        $this->assertSame('npm', $this->service->detectNodePackageManagerFromPackageJson($root));

        $bootstrap = $this->service->nodeBootstrap($app, $root, 'apps/web');
        $this->assertStringContainsString('/usr/local/bin/npm', $bootstrap);
        $this->assertStringContainsString('npm --prefix apps/web run build', $bootstrap);
        $this->assertStringNotContainsString('corepack pnpm', $bootstrap);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $app,
            false,
            false,
            false,
            3000,
            '/app/apps/web',
            $root,
            '/app'
        );
        $this->assertStringContainsString('exec npx next start', $runtime->command[2]);
        $this->assertStringNotContainsString('corepack pnpm', $runtime->command[2]);
    }

    #[Test]
    public function workspace_protocol_still_selects_pnpm_when_package_manager_is_unset(): void
    {
        $app = json_encode([
            'dependencies' => [
                '@repo/ui' => 'workspace:*',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertSame('pnpm', $this->service->detectNodePackageManagerFromPackageJson($app));
        $this->assertSame('pnpm', $this->service->resolveNodePackageManager($app, '{"private":true}'));
    }

    #[Test]
    public function malformed_package_manager_is_not_misclassified_as_yarn(): void
    {
        $package = '{"packageManager":"yarn@npm@10.9.3"}';

        $this->assertNull($this->service->declaredNodePackageManagerFromPackageJson($package));
        $this->assertSame('yarn@npm@10.9.3', $this->service->malformedNodePackageManagerFromPackageJson($package));
        $this->assertSame('npm', $this->service->resolveNodePackageManager($package));
    }

    #[Test]
    public function production_next_runtime_refuses_to_start_without_a_build_id(): void
    {
        $package = json_encode([
            'scripts' => ['start' => 'next start'],
            'dependencies' => ['next' => '15.5.25'],
        ], JSON_THROW_ON_ERROR);

        $runtime = $this->service->detectNodeFromContents(
            null,
            $package,
            false,
            false,
            false,
            3000,
            '/app/apps/web',
            '{"private":true}',
            '/app',
            includeBootstrap: false,
        );

        $this->assertStringContainsString('[ ! -f apps/web/.next/BUILD_ID ]', $runtime->command[2]);
        $this->assertStringContainsString('production-start-no-build-id', $runtime->command[2]);
        $this->assertStringNotContainsString('npm install', $runtime->command[2]);
    }
}
