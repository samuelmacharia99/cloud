<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\Provisioning\ContainerDomainBindingService;
use App\Services\Provisioning\ContainerGoLiveService;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ContainerGoLiveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_dns_gets_an_instruction_and_no_wait(): void
    {
        [$service] = $this->boundService();
        $dns = Mockery::mock(DomainCloudflareDnsService::class);
        $dns->shouldReceive('resolvePlatformDomainForHostname')->once()->andReturn(null);
        $nginx = Mockery::mock(NginxProxyService::class);
        $nginx->shouldReceive('checkDns')->twice()->andReturn(false);
        $nginx->shouldNotReceive('enableSsl');

        $lines = [];
        $result = $this->service($dns, $nginx)->goLive($service, 'example.com', function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertFalse($result['managed']);
        $this->assertSame([], $result['resolved']);
        $this->assertStringContainsString('DNS for example.com is external: point example.com and www.example.com at 203.0.113.10', $lines[0]);
        $this->assertStringContainsString('SSL waits until the A records point here', end($lines));
    }

    public function test_managed_dns_waits_then_issues_certificates_for_what_resolves(): void
    {
        [$service, $deployment] = $this->boundService();
        $zone = Domain::query()->create(['user_id' => $service->user_id, 'name' => 'example', 'extension' => '.com', 'cloudflare_dns_enabled' => true]);
        $dns = Mockery::mock(DomainCloudflareDnsService::class);
        $dns->shouldReceive('resolvePlatformDomainForHostname')->once()->andReturn($zone);
        $nginx = Mockery::mock(NginxProxyService::class);
        // apex resolves on the first check, www never does within the window.
        $nginx->shouldReceive('checkDns')->with('example.com', '203.0.113.10')->once()->andReturn(true);
        $nginx->shouldReceive('checkDns')->with('www.example.com', '203.0.113.10')->andReturn(false);
        $nginx->shouldReceive('enableSsl')->once()->withArgs(fn (ContainerDomain $d) => $d->domain === 'example.com');

        $lines = [];
        $result = $this->service($dns, $nginx)->goLive($service, 'example.com', function (string $line) use (&$lines) {
            $lines[] = $line;
        }, waitSeconds: 1);

        $this->assertTrue($result['managed']);
        $this->assertSame(['example.com'], $result['resolved']);
        $this->assertSame(['example.com' => true], $result['ssl']);
        $this->assertStringContainsString('managed by Talksasa', $lines[0]);
        $this->assertContains('https://example.com is live', $lines);
        $this->assertContains('Live with HTTPS: https://example.com', $lines);
    }

    public function test_certificate_failure_is_reported_and_left_to_the_scheduler(): void
    {
        [$service] = $this->boundService();
        $zone = Domain::query()->create(['user_id' => $service->user_id, 'name' => 'example', 'extension' => '.com', 'cloudflare_dns_enabled' => true]);
        $dns = Mockery::mock(DomainCloudflareDnsService::class);
        $dns->shouldReceive('resolvePlatformDomainForHostname')->andReturn($zone);
        $nginx = Mockery::mock(NginxProxyService::class);
        $nginx->shouldReceive('checkDns')->andReturn(true);
        $nginx->shouldReceive('enableSsl')->twice()->andThrow(new \RuntimeException('certbot: too many requests'));

        $lines = [];
        $result = $this->service($dns, $nginx)->goLive($service, 'example.com', function (string $line) use (&$lines) {
            $lines[] = $line;
        }, waitSeconds: 0);

        $this->assertSame(['example.com' => false, 'www.example.com' => false], $result['ssl']);
        $this->assertStringContainsString('Certificate for example.com failed: certbot: too many requests The scheduler retries.', implode("\n", $lines));
    }

    private function service(DomainCloudflareDnsService $dns, NginxProxyService $nginx): ContainerGoLiveService
    {
        return new ContainerGoLiveService($dns, $nginx, app(ContainerDomainBindingService::class));
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function boundService(): array
    {
        $node = Node::factory()->containerHost()->create(['ip_address' => '203.0.113.10']);
        $service = Service::factory()->create(['user_id' => User::factory()->create()->id, 'node_id' => $node->id, 'provisioning_driver_key' => 'container']);
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id, 'node_id' => $node->id]);
        foreach (['example.com', 'www.example.com'] as $host) {
            ContainerDomain::query()->create(['container_deployment_id' => $deployment->id, 'domain' => $host, 'purpose' => ContainerDomain::PURPOSE_WEB, 'status' => 'active', 'ssl_enabled' => false]);
        }

        return [$service->fresh(), $deployment];
    }
}
