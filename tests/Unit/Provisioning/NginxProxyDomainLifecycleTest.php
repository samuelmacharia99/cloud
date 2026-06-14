<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NginxProxyDomainLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbind_deletes_record_when_node_is_missing(): void
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => null,
        ]);

        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'orphan.example.com',
            'status' => 'active',
        ]);

        app(NginxProxyService::class)->unbind($domain);

        $this->assertDatabaseMissing('container_domains', ['id' => $domain->id]);
    }

    public function test_customer_update_domain_redirects_to_domains_tab(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'old.example.com',
            'status' => 'active',
        ]);

        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('removeProxyConfig')->once();
            $mock->shouldReceive('cleanupSslCertificate')->once();
            $mock->shouldReceive('bind')->once();
            $mock->shouldReceive('checkDns')->andReturn(false);
        });

        $response = $this->actingAs($user)->patch(
            route('customer.services.container.domains.update', [$service, $domain]),
            ['domain' => 'new.example.com']
        );

        $response->assertRedirect(route('customer.services.container.show', [
            'service' => $service,
            'tab' => 'domains',
        ]));

        $this->assertDatabaseHas('container_domains', [
            'id' => $domain->id,
            'domain' => 'new.example.com',
            'status' => 'pending',
        ]);
    }

    public function test_customer_unbind_domain_redirects_to_domains_tab(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'remove-me.example.com',
            'status' => 'active',
        ]);

        $this->mock(NginxProxyService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('unbind')
                ->once()
                ->with(\Mockery::on(fn ($record) => $record->id === $domain->id));
        });

        $response = $this->actingAs($user)->delete(
            route('customer.services.container.domains.unbind', [$service, $domain])
        );

        $response->assertRedirect(route('customer.services.container.show', [
            'service' => $service,
            'tab' => 'domains',
        ]));
    }
}
