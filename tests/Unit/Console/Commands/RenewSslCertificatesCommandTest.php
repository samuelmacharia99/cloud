<?php

namespace Tests\Unit\Console\Commands;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\NginxProxyService;
use App\Services\Provisioning\PlatformAppsDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RenewSslCertificatesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function platform_hostnames_renew_through_the_wildcard_and_customer_domains_one_by_one(): void
    {
        $node = Node::factory()->containerHost()->create();
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'node_id' => $node->id,
        ]);
        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'shop.example.com',
            'status' => 'active',
            'ssl_enabled' => true,
        ]);
        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => $deployment->container_name.'.apps.example.com',
            'purpose' => ContainerDomain::PURPOSE_PLATFORM,
            'status' => 'active',
            'ssl_enabled' => true,
        ]);

        $this->mock(NginxProxyService::class, function ($mock): void {
            $mock->shouldReceive('renewSsl')->once()->withArgs(fn (ContainerDomain $domain): bool => $domain->domain === 'shop.example.com');
        });
        $this->mock(PlatformAppsDomainService::class, function ($mock): void {
            $mock->shouldReceive('renewCertificates')->once()->andReturn(['renewed' => 1, 'failed' => 0, 'errors' => []]);
        });

        $this->artisan('cron:renew-ssl-certificates')
            ->expectsOutputToContain('Renewed SSL certificates for 1 domains. Failed: 0. Platform wildcard renewed on 1 node(s), failed on 0.')
            ->assertExitCode(0);
    }
}
