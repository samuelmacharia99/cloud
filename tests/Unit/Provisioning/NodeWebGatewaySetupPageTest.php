<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\NodeWebGatewayProxy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The edge holds the site on a setup notice while the API waits for values only
 * the customer can supply. Proxying in that state would only serve 502s.
 */
class NodeWebGatewaySetupPageTest extends TestCase
{
    #[Test]
    public function the_gateway_reads_the_setup_flag_from_its_environment(): void
    {
        $script = NodeWebGatewayProxy::scriptContents();

        $this->assertStringContainsString('GATEWAY_SETUP_REQUIRED', $script);
        $this->assertSame('GATEWAY_SETUP_REQUIRED', NodeWebGatewayProxy::SETUP_REQUIRED_ENV);
    }

    #[Test]
    public function it_answers_every_request_with_the_notice_while_held(): void
    {
        $script = NodeWebGatewayProxy::scriptContents();

        $this->assertStringContainsString('if (setupRequired) {', $script);
        $this->assertStringContainsString('serveSetupPage(res)', $script);
        $this->assertStringContainsString('res.writeHead(503', $script);
        $this->assertStringContainsString("'x-talksasa-upstream': 'setup'", $script);
    }

    #[Test]
    public function it_refuses_websocket_upgrades_while_held(): void
    {
        $script = NodeWebGatewayProxy::scriptContents();

        $this->assertStringContainsString('socket.destroy();', $script);
    }

    #[Test]
    public function it_escapes_variable_names_before_listing_them(): void
    {
        $script = NodeWebGatewayProxy::scriptContents();

        $this->assertStringContainsString('function escapeHtml(', $script);
        $this->assertStringContainsString('escapeHtml(name)', $script);
    }

    #[Test]
    public function it_still_routes_normally_when_no_hold_is_set(): void
    {
        $script = NodeWebGatewayProxy::scriptContents();

        // The proxy path must survive: /api to the API, everything else to the site.
        $this->assertStringContainsString("hasPrefix('/api')", $script);
        $this->assertStringContainsString("role: 'backend'", $script);
        $this->assertStringContainsString("role: 'frontend'", $script);
    }
}
