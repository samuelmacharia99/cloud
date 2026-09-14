<?php

namespace Tests\Feature\Console;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class IssuePendingSslCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_only_for_unsecured_web_hostnames_whose_dns_points_at_the_node(): void
    {
        $node = Node::factory()->containerHost()->create(['ip_address' => '203.0.113.10']);
        $service = Service::factory()->create(['node_id' => $node->id, 'provisioning_driver_key' => 'container']);
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id, 'node_id' => $node->id]);

        $ready = $this->domain($deployment, 'ready.example.com');
        $waiting = $this->domain($deployment, 'waiting.example.com');
        $secured = $this->domain($deployment, 'secured.example.com', ['ssl_enabled' => true]);
        $platform = $this->domain($deployment, 'app.apps.example', ['purpose' => ContainerDomain::PURPOSE_PLATFORM]);
        $backoff = $this->domain($deployment, 'backoff.example.com', ['error_message' => 'certbot failed']);
        $stale = $this->domain($deployment, 'stale.example.com');
        ContainerDomain::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(30)]);

        $this->mock(NginxProxyService::class, function (MockInterface $nginx) {
            $nginx->shouldReceive('checkDns')->with('ready.example.com', '203.0.113.10')->once()->andReturn(true);
            $nginx->shouldReceive('checkDns')->with('waiting.example.com', '203.0.113.10')->once()->andReturn(false);
            $nginx->shouldReceive('enableSsl')->once()->withArgs(fn (ContainerDomain $d) => $d->domain === 'ready.example.com');
        });

        $this->artisan('cron:issue-pending-ssl')
            ->expectsOutputToContain('Issued 1 first certificate(s), 0 failed, 1 still waiting for DNS, 1 skipped')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function domain(ContainerDeployment $deployment, string $host, array $overrides = []): ContainerDomain
    {
        return ContainerDomain::query()->create(array_merge([
            'container_deployment_id' => $deployment->id,
            'domain' => $host,
            'purpose' => ContainerDomain::PURPOSE_WEB,
            'status' => 'active',
            'ssl_enabled' => false,
        ], $overrides));
    }
}
