<?php

namespace Tests\Unit\Terminal;

use App\Exceptions\SSH\SSHCommandException;
use App\Services\Terminal\ContainerTerminalService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ContainerTerminalServiceTest extends TestCase
{
    #[Test]
    public function it_allows_long_timeouts_for_artisan_migration_commands(): void
    {
        config([
            'terminal.command_timeouts.artisan_long' => 900,
            'terminal.command_timeouts.artisan' => 600,
            'terminal.command_timeouts.default' => 30,
        ]);

        $service = new ContainerTerminalService;
        $method = new ReflectionMethod(ContainerTerminalService::class, 'commandTimeoutSeconds');
        $method->setAccessible(true);

        $this->assertSame(
            900,
            $method->invoke($service, 'php artisan migrate:fresh --seed --force')
        );
        $this->assertSame(
            600,
            $method->invoke($service, 'php artisan config:cache')
        );
        $this->assertSame(
            30,
            $method->invoke($service, 'ls -la')
        );
    }

    #[Test]
    public function it_formats_partial_output_when_ssh_command_fails(): void
    {
        $service = new ContainerTerminalService;
        $method = new ReflectionMethod(ContainerTerminalService::class, 'formatFailedCommandOutput');
        $method->setAccessible(true);

        $formatted = $method->invoke($service, new SSHCommandException(
            'docker exec test',
            "Running migrations.\n  2024_01_01_create_tables",
            'Command exited with status 124'
        ));

        $this->assertStringContainsString('Running migrations.', $formatted);
        $this->assertStringContainsString('Partial output is shown below', $formatted);
        $this->assertStringContainsString('Command exited with status 124', $formatted);
    }
}
