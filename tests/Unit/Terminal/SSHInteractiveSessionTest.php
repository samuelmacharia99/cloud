<?php

namespace Tests\Unit\Terminal;

use App\Services\Terminal\SSHInteractiveSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SSHInteractiveSessionTest extends TestCase
{
    #[Test]
    public function it_allocates_a_container_tty_and_keeps_the_app_workdir(): void
    {
        $command = SSHInteractiveSession::buildDockerShellCommand('user-1-service-454-nodejs', null, '/app');

        $this->assertStringContainsString('stty raw -echo', $command);
        $this->assertStringContainsString('docker exec -i -t', $command);
        $this->assertStringContainsString("-w '/app'", $command);
        $this->assertStringContainsString("'user-1-service-454-nodejs'", $command);
        $this->assertStringContainsString('exec /bin/bash -i', $command);
        $this->assertStringNotContainsString('exec /bin/bash -l', $command);
        $this->assertStringContainsString('TERM=xterm-256color', $command);
    }

    #[Test]
    public function it_passes_the_stack_user_and_wordpress_html_root(): void
    {
        $command = SSHInteractiveSession::buildDockerShellCommand(
            'user-2-service-22-wordpress',
            'www-data',
            '/var/www/html',
        );

        $this->assertStringContainsString("-u 'www-data'", $command);
        $this->assertStringContainsString("-w '/var/www/html'", $command);
        $this->assertStringNotContainsString('-w /app ', $command);
    }

    #[Test]
    public function it_rejects_unsafe_workdir_values(): void
    {
        $command = SSHInteractiveSession::buildDockerShellCommand('app', 'app', 'app');

        $this->assertStringContainsString("-w '/app'", $command);
    }
}
