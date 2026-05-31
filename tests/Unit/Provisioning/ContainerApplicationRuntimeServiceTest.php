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
}
