<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\LaravelNextGatewayProxy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LaravelNextGatewayProxyTest extends TestCase
{
    #[Test]
    public function it_builds_a_gateway_command_and_script(): void
    {
        $command = LaravelNextGatewayProxy::composeCommand(
            '/app/backend/public',
            '/app/frontend',
            8000,
            8001,
            3000,
        );

        $this->assertSame('sh', $command[0]);
        $this->assertSame('-lc', $command[1]);
        $this->assertStringContainsString('artisan serve', $command[2]);
        $this->assertStringContainsString('.talksasa-next-gateway.js', $command[2]);

        $script = LaravelNextGatewayProxy::scriptContents(8000, 8001, 3000);
        $this->assertStringContainsString("startsWith('/api')", $script);
        $this->assertStringContainsString('Talksasa gateway listening', $script);
    }
}
