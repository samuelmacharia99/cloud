<?php

namespace Tests\Unit\Terminal;

use App\Services\Terminal\TerminalSecurityGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TerminalSecurityGuardTest extends TestCase
{
    #[Test]
    public function it_allows_curl_and_process_signals(): void
    {
        $guard = new TerminalSecurityGuard;

        $this->assertTrue($guard->validate('curl -I https://example.com')['allowed']);
        $this->assertTrue($guard->validate('wget -qO- https://example.com')['allowed']);
        $this->assertTrue($guard->validate('kill 1234')['allowed']);
        $this->assertTrue($guard->validate('pkill php-fpm')['allowed']);
    }

    #[Test]
    public function it_blocks_host_escape_with_helpful_hints(): void
    {
        $guard = new TerminalSecurityGuard;

        $docker = $guard->validate('docker ps');
        $this->assertFalse($docker['allowed']);
        $this->assertStringContainsString('Docker', $docker['reason']);
        $this->assertNotEmpty($docker['hint']);
        $this->assertStringContainsString('overview', strtolower($guard->formatBlockMessage($docker)));

        $sudo = $guard->validate('sudo apt update');
        $this->assertFalse($sudo['allowed']);

        $ssh = $guard->validate('ssh user@host');
        $this->assertFalse($ssh['allowed']);
    }

    #[Test]
    public function it_rejects_oversized_commands_before_truncating_silently(): void
    {
        config(['terminal.security.max_command_length' => 1024]);
        $guard = new TerminalSecurityGuard;
        $long = str_repeat('a', 2000);

        $result = $guard->validate($long);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('1024', $result['reason']);
    }
}
