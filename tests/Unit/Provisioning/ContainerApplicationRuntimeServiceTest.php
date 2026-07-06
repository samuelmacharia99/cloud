<?php

namespace Tests\Unit\Provisioning;

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

        $this->assertStringContainsString('env -i HOME=/tmp', $bootstrap);
        $this->assertStringContainsString('/usr/local/bin/npm install --production=false --include=dev', $bootstrap);
        $this->assertStringContainsString('/usr/local/bin/npm run build', $bootstrap);
        $this->assertStringContainsString('.next/BUILD_ID', $bootstrap);
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
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_ENV=development /usr/local/bin/npm install --production=false --include=dev --no-audit --no-fund',
            $runtime->npmInstallShellCommand()
        );
        $this->assertSame(
            'env -i HOME=/tmp NPM_CONFIG_CACHE=/tmp/.npm PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin npm_config_production=false NPM_CONFIG_PRODUCTION=false npm_config_omit= NODE_OPTIONS=--max-old-space-size=650 NODE_ENV=production /usr/local/bin/npm run build',
            $runtime->npmBuildShellCommand(1000)
        );
        $this->assertStringContainsString(
            'NODE_OPTIONS=--max-old-space-size=4096',
            $runtime->npmBuildShellCommand(null, true)
        );
        $this->assertStringContainsString(
            '/usr/local/bin/npm ci --include=dev --no-audit --no-fund',
            $runtime->npmCiShellCommand()
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

        $this->assertStringContainsString('npm run build', $runtime->command[2]);
        $this->assertStringContainsString('exec npm start', $runtime->command[2]);
    }
}
