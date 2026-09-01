<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\Domain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerDomainBindTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_a_domain_also_adds_www_and_managed_a_records(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = Node::factory()->containerHost()->create(['ip_address' => '10.20.30.40']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $platform = Domain::create([
            'user_id' => $user->id,
            'name' => 'acme',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-acme',
        ]);

        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->andReturnNull();
            $mock->shouldReceive('checkDns')->andReturn(false);
            $mock->shouldReceive('enableSsl')->never();
        });

        $this->mock(DomainCloudflareDnsService::class, function ($mock) use ($platform) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')
                ->andReturn($platform);
            $mock->shouldReceive('upsertARecord')
                ->twice()
                ->andReturn(['success' => true, 'message' => 'ok']);
        });

        $this->actingAs($user)
            ->post(route('customer.services.container.domains.bind', $service), [
                'domain' => 'acme.com',
            ])
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'domains',
            ]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('container_domains', ['domain' => 'acme.com']);
        $this->assertDatabaseHas('container_domains', ['domain' => 'www.acme.com']);
    }
}
