<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\Provisioning\ContainerDomainBindingService;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerDomainBindingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->andReturnNull();
            $mock->shouldReceive('checkDns')->andReturn(false);
            $mock->shouldReceive('enableSsl')->never();
        });
    }

    public function test_hostnames_for_adds_apex_and_www(): void
    {
        $service = app(ContainerDomainBindingService::class);

        $this->assertSame(
            ['example.com', 'www.example.com'],
            $service->hostnamesFor('https://Example.COM/about')
        );
        $this->assertSame(
            ['example.com', 'www.example.com'],
            $service->hostnamesFor('www.example.com')
        );
        $this->assertSame(
            ['shop.example.co.ke', 'www.shop.example.co.ke'],
            $service->hostnamesFor('shop.example.co.ke')
        );
    }

    public function test_attach_primary_hosts_binds_apex_and_www(): void
    {
        $context = $this->makeService(['example.com']);

        $bound = app(ContainerDomainBindingService::class)->attachPrimaryHosts($context['service']);

        $this->assertCount(2, $bound);
        $this->assertDatabaseHas('container_domains', [
            'container_deployment_id' => $context['deployment']->id,
            'domain' => 'example.com',
        ]);
        $this->assertDatabaseHas('container_domains', [
            'container_deployment_id' => $context['deployment']->id,
            'domain' => 'www.example.com',
        ]);
    }

    public function test_managed_dns_upserts_a_records_for_apex_and_www(): void
    {
        $context = $this->makeService(['example.com']);
        $platform = Domain::create([
            'user_id' => $context['user']->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-1',
        ]);

        $this->mock(DomainCloudflareDnsService::class, function ($mock) use ($platform) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')
                ->andReturn($platform);
            $mock->shouldReceive('upsertARecord')
                ->twice()
                ->andReturn(['success' => true, 'message' => 'ok']);
        });

        app(ContainerDomainBindingService::class)->attachPrimaryHosts($context['service']->fresh());
    }

    public function test_unmanaged_dns_does_not_write_a_records(): void
    {
        $context = $this->makeService(['example.com']);

        $this->mock(DomainCloudflareDnsService::class, function ($mock) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->andReturn(null);
            $mock->shouldReceive('upsertARecord')->never();
        });

        app(ContainerDomainBindingService::class)->attachPrimaryHosts($context['service']->fresh());

        $this->assertDatabaseHas('container_domains', ['domain' => 'example.com']);
        $this->assertDatabaseHas('container_domains', ['domain' => 'www.example.com']);
    }

    public function test_skips_hostname_bound_to_another_application(): void
    {
        $first = $this->makeService();
        ContainerDomain::create([
            'container_deployment_id' => $first['deployment']->id,
            'domain' => 'taken.com',
            'status' => 'active',
        ]);

        $second = $this->makeService(['taken.com'], $first['deployment']->node_id);
        $bound = app(ContainerDomainBindingService::class)->attachPrimaryHosts($second['service']);

        $this->assertCount(1, $bound);
        $this->assertSame('www.taken.com', $bound[0]->domain);
        $this->assertSame($first['deployment']->id, ContainerDomain::query()->where('domain', 'taken.com')->value('container_deployment_id'));
    }

    /**
     * @return array{user: User, service: Service, deployment: ContainerDeployment}
     */
    private function makeService(?array $metaDomain = null, ?int $nodeId = null): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = $nodeId
            ? Node::query()->findOrFail($nodeId)
            : Node::factory()->containerHost()->create();
        $meta = [];
        if ($metaDomain) {
            $meta['domain'] = $metaDomain[0];
        }

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'service_meta' => $meta,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return [
            'user' => $user,
            'service' => $service->fresh(['product', 'containerDeployment.node']),
            'deployment' => $deployment,
        ];
    }
}
