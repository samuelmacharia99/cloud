<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerApplicationRuntimeService;
use App\Services\Provisioning\ContainerDoctorInfrastructureAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Python API restarted forever and printed nothing at all.
 *
 * The start command was `[ -f requirements.txt ] && pip install … && exec
 * uvicorn …`. With the file absent, the test itself was the failing command:
 * the shell exited 1 before reaching the application, with no output, and
 * Docker restarted it forever. The platform was manufacturing the one failure
 * that cannot be diagnosed from a log.
 */
class ContainerSilentExitTest extends TestCase
{
    #[Test]
    public function a_missing_requirements_file_no_longer_stops_the_app_from_starting(): void
    {
        $bootstrap = $this->bootstrap('pythonBootstrap');

        $this->assertStringContainsString('if [ -f requirements.txt ]; then', $bootstrap);
        $this->assertStringNotContainsString('[ -f requirements.txt ] &&', $bootstrap);
    }

    #[Test]
    public function the_same_guard_is_fixed_for_ruby_and_node(): void
    {
        $this->assertStringContainsString('if [ -f Gemfile ]; then', $this->bootstrap('rubyBootstrap'));
        $this->assertStringNotContainsString('[ -f Gemfile ] &&', $this->bootstrap('rubyBootstrap'));
    }

    #[Test]
    public function the_start_command_still_reaches_the_application(): void
    {
        $runtime = app(ContainerApplicationRuntimeService::class)->shellRuntime(
            'uvicorn main:app --host 0.0.0.0 --port ${PORT:-8000}',
            8000,
            'fastapi',
            'FastAPI',
            $this->bootstrap('pythonBootstrap'),
            '/app/apps/backend',
        );

        $script = $runtime->command[2] ?? '';

        $this->assertStringContainsString('fi && exec uvicorn main:app', $script);
        $this->assertStringContainsString('cd /app/apps/backend', $script);
    }

    #[Test]
    public function doctor_names_a_container_that_exits_without_saying_why(): void
    {
        $findings = app(ContainerDoctorInfrastructureAnalyzer::class)->findings('', 'python', [
            'restarting' => true,
            'status' => 'Restarting (1)',
            'image' => 'python:3.11-slim',
            'cmd' => 'sh -lc cd /app/apps/backend && ...',
        ]);

        $ids = array_column($findings, 'id');

        $this->assertContains('container_exits_without_output', $ids);
        $this->assertNotContains(
            'container_crash_loop',
            $ids,
            'Telling somebody to read a log that does not exist is worse than saying nothing.'
        );

        $finding = collect($findings)->firstWhere('id', 'container_exits_without_output');
        $this->assertStringContainsString('requirements.txt', $finding['summary']);
    }

    #[Test]
    public function a_container_that_did_explain_itself_keeps_the_ordinary_crash_loop_finding(): void
    {
        $findings = app(ContainerDoctorInfrastructureAnalyzer::class)->findings(
            "Traceback (most recent call last):\nModuleNotFoundError: No module named 'app'",
            'python',
            ['restarting' => true, 'status' => 'Restarting (1)'],
        );

        $ids = array_column($findings, 'id');

        $this->assertContains('container_crash_loop', $ids);
        $this->assertNotContains('container_exits_without_output', $ids);
    }

    #[Test]
    public function dockers_own_restart_notices_do_not_count_as_the_app_speaking(): void
    {
        $analyzer = app(ContainerDoctorInfrastructureAnalyzer::class);

        $this->assertTrue($analyzer->logIsSilent("Restarting (1) 3 seconds ago\nContainer user-1-app Restarting\n"));
        $this->assertFalse($analyzer->logIsSilent("Restarting (1)\nModuleNotFoundError: No module named 'app'"));
    }

    private function bootstrap(string $method): string
    {
        $service = app(ContainerApplicationRuntimeService::class);
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return (string) $reflection->invoke($service);
    }
}
