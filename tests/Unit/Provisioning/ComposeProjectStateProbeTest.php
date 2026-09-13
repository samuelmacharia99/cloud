<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ComposeProjectStateProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ComposeProjectStateProbeTest extends TestCase
{
    #[Test]
    public function it_groups_rows_per_compose_project_and_keeps_health_text(): void
    {
        $output = implode("\r\n", [
            'user-1-service-9-laravel|user-1-service-9-laravel|user-1-service-9-laravel|running|Up 3 hours|127.0.0.1:31025->8000/tcp',
            'user-1-service-9-laravel|db|user-1-service-9-laravel-db|exited|Exited (1) 12 minutes ago|',
            'user-1-service-9-laravel|edge|user-1-service-9-laravel-edge|running|Up 3 hours (healthy)|8080/tcp, 0.0.0.0:31030->80/tcp',
            'user-2-service-10-wordpress|mysql|user-2-service-10-wordpress-mysql|running|Up 2 days (health: starting)|3306/tcp',
            '||orphan-without-project|running|Up|',
            '',
        ]);

        $projects = ComposeProjectStateProbe::parse($output);

        $this->assertSame(['user-1-service-9-laravel', 'user-2-service-10-wordpress'], array_keys($projects));
        $this->assertSame(['user-1-service-9-laravel', 'user-1-service-9-laravel-db', 'user-1-service-9-laravel-edge'], array_keys($projects['user-1-service-9-laravel']));
        $this->assertSame('exited', $projects['user-1-service-9-laravel']['user-1-service-9-laravel-db']['state']);
        $this->assertSame('db', $projects['user-1-service-9-laravel']['user-1-service-9-laravel-db']['service']);
        $this->assertSame('Up 3 hours (healthy)', $projects['user-1-service-9-laravel']['user-1-service-9-laravel-edge']['status']);
        $this->assertSame('8080/tcp, 0.0.0.0:31030->80/tcp', $projects['user-1-service-9-laravel']['user-1-service-9-laravel-edge']['ports']);
    }

    #[Test]
    public function empty_output_means_no_projects(): void
    {
        $this->assertSame([], ComposeProjectStateProbe::parse(''));
        $this->assertSame([], ComposeProjectStateProbe::parse("\n\n"));
    }

    #[Test]
    public function the_commands_filter_on_the_compose_project_label(): void
    {
        $command = ComposeProjectStateProbe::projectCommand("user-1-service-9-laravel'; rm -rf /");

        $this->assertStringContainsString("--filter 'label=com.docker.compose.project=user-1-service-9-laravel'\\''; rm -rf /'", $command);
        $this->assertStringContainsString('com.docker.compose.service', $command);
        $this->assertStringContainsString('--filter label=com.docker.compose.project --format', ComposeProjectStateProbe::nodeCommand());
    }
}
