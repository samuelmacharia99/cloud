<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\LaravelNextGatewayProxy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LaravelNextGatewayProxyTest extends TestCase
{
    #[Test]
    public function it_builds_sidecar_commands_and_edge_script(): void
    {
        $backend = LaravelNextGatewayProxy::backendComposeCommand('/app/backend/public', 8000);
        $this->assertSame('sh', $backend[0]);
        $this->assertStringContainsString('artisan serve', $backend[2]);
        $this->assertStringContainsString('0.0.0.0', $backend[2]);
        // Docker Compose treats $VAR as host interpolation — shell vars must be $$.
        $this->assertStringContainsString('$$BACKEND_DIR', $backend[2]);
        $this->assertStringNotContainsString('BACKEND_DIR=$(', $backend[2]);

        $frontend = LaravelNextGatewayProxy::frontendComposeCommand('/app/frontend', 3000);
        $this->assertStringContainsString('0.0.0.0', $frontend[2]);
        $this->assertStringContainsString('standalone', $frontend[2]);
        $this->assertStringContainsString('Waiting for Next.js build', $frontend[2]);
        $this->assertStringContainsString('$$(seq', $frontend[2]);

        $script = LaravelNextGatewayProxy::scriptContents(8080, 8000, 3000);
        $this->assertStringContainsString("startsWith('/api')", $script);
        $this->assertStringContainsString('BACKEND_HOST', $script);
        $this->assertStringContainsString('FRONTEND_HOST', $script);
        $this->assertStringContainsString('Talksasa edge listening', $script);
    }

    #[Test]
    public function legacy_single_container_command_still_available(): void
    {
        $command = LaravelNextGatewayProxy::composeCommand(
            '/app/backend/public',
            '/app/frontend',
            8000,
            8001,
            3000,
        );

        $this->assertSame('sh', $command[0]);
        $this->assertStringContainsString('artisan serve', $command[2]);
        $this->assertStringContainsString('.talksasa-next-gateway.js', $command[2]);
    }
}
