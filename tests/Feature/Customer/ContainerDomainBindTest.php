<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
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

    public function test_api_only_node_service_binds_exactly_one_api_hostname(): void
    {
        [$user, $service, $deployment] = $this->apiOnlyNodeService();
        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->once();
            $mock->shouldReceive('checkDns')->once()->andReturn(false);
            $mock->shouldReceive('enableSsl')->never();
        });
        $this->mock(DomainCloudflareDnsService::class, function ($mock) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->andReturn(null);
            $mock->shouldReceive('upsertARecord')->never();
        });

        $this->actingAs($user)
            ->post(route('customer.services.container.domains.bind', $service), [
                'domain' => 'api.example.com',
                'purpose' => 'api',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('container_domains', [
            'container_deployment_id' => $deployment->id,
            'domain' => 'api.example.com',
            'purpose' => ContainerDomain::PURPOSE_API,
        ]);
        $this->assertDatabaseMissing('container_domains', ['domain' => 'www.api.example.com']);
        $service->refresh();
        $this->assertSame('api.example.com', $service->service_meta['api_hostname']);
        $this->assertSame('https://api.example.com', $service->service_meta['api_url']);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'container_deployment_id' => $deployment->id,
            'event' => 'api_domain_bound',
        ]);
    }

    public function test_api_only_node_console_exposes_dedicated_hostname_workflow(): void
    {
        [$user, $service] = $this->apiOnlyNodeService();

        $this->actingAs($user)
            ->get(route('customer.services.container.show', $service).'?tab=domains')
            ->assertOk()
            ->assertSee('Public API endpoint')
            ->assertSee('Configure API domain')
            ->assertSee('name="purpose" value="api"', false)
            ->assertSee('api.example.com');
    }

    public function test_api_only_node_service_cannot_accidentally_bind_two_api_hostnames(): void
    {
        [$user, $service, $deployment] = $this->apiOnlyNodeService();
        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'api.example.com',
            'purpose' => ContainerDomain::PURPOSE_API,
            'status' => 'active',
        ]);
        $this->mock(NginxProxyService::class, fn ($mock) => $mock->shouldReceive('bind')->never());
        $this->mock(DomainCloudflareDnsService::class, function ($mock) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->never();
            $mock->shouldReceive('upsertARecord')->never();
        });

        $this->actingAs($user)->post(route('customer.services.container.domains.bind', $service), [
            'domain' => 'api2.example.com',
            'purpose' => 'api',
        ])->assertSessionHasErrors('error');

        $this->assertDatabaseMissing('container_domains', ['domain' => 'api2.example.com']);
    }

    public function test_existing_hostname_on_same_service_can_be_designated_as_api_endpoint(): void
    {
        [$user, $service, $deployment] = $this->apiOnlyNodeService();
        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'api.example.com',
            'purpose' => ContainerDomain::PURPOSE_WEB,
            'status' => 'active',
        ]);
        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->once();
            $mock->shouldReceive('checkDns')->once()->andReturn(false);
            $mock->shouldReceive('enableSsl')->never();
        });
        $this->mock(DomainCloudflareDnsService::class, function ($mock) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->andReturn(null);
            $mock->shouldReceive('upsertARecord')->never();
        });

        $this->actingAs($user)->post(route('customer.services.container.domains.bind', $service), [
            'domain' => 'api.example.com',
            'purpose' => 'api',
        ])->assertSessionHas('success');

        $this->assertSame(ContainerDomain::PURPOSE_API, $domain->fresh()->purpose);
        $this->assertSame(1, ContainerDomain::query()->where('domain', 'api.example.com')->count());
    }

    public function test_api_domain_uses_managed_dns_when_customer_owns_the_zone(): void
    {
        [$user, $service] = $this->apiOnlyNodeService();
        $platform = Domain::create([
            'user_id' => $user->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-api',
        ]);
        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->once();
            $mock->shouldReceive('checkDns')->once()->andReturn(false);
            $mock->shouldReceive('enableSsl')->never();
        });
        $this->mock(DomainCloudflareDnsService::class, function ($mock) use ($platform) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->twice()->andReturn($platform);
            $mock->shouldReceive('upsertARecord')
                ->once()
                ->with(\Mockery::on(fn ($domain) => $domain->is($platform)), 'api.example.com', '10.20.30.40')
                ->andReturn(['success' => true, 'message' => 'ok']);
        });

        $this->actingAs($user)->post(route('customer.services.container.domains.bind', $service), [
            'domain' => 'api.example.com',
            'purpose' => 'api',
        ])->assertSessionHas('success');
    }

    public function test_managed_dns_failure_does_not_publish_api_endpoint_metadata(): void
    {
        [$user, $service] = $this->apiOnlyNodeService();
        $platform = Domain::create([
            'user_id' => $user->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-api',
        ]);
        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->never();
        });
        $this->mock(DomainCloudflareDnsService::class, function ($mock) use ($platform) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->once()->andReturn($platform);
            $mock->shouldReceive('upsertARecord')->once()->andReturn([
                'success' => false,
                'message' => 'provider unavailable',
            ]);
        });

        $this->actingAs($user)->post(route('customer.services.container.domains.bind', $service), [
            'domain' => 'api.example.com',
            'purpose' => 'api',
        ])->assertSessionHasErrors('error');

        $this->assertDatabaseHas('container_domains', [
            'domain' => 'api.example.com',
            'purpose' => ContainerDomain::PURPOSE_API,
            'status' => 'failed',
        ]);
        $service->refresh();
        $this->assertArrayNotHasKey('api_hostname', $service->service_meta);
    }

    public function test_split_node_service_cannot_claim_an_api_only_hostname(): void
    {
        [$user, $service] = $this->apiOnlyNodeService(['frontend' => 'vite-spa']);
        $this->actingAs($user)->post(route('customer.services.container.domains.bind', $service), [
            'domain' => 'api.example.com',
            'purpose' => 'api',
        ])->assertSessionHasErrors('purpose');

        $this->assertDatabaseMissing('container_domains', ['domain' => 'api.example.com']);
    }

    public function test_customer_cannot_bind_api_hostname_to_another_customers_service(): void
    {
        [, $service] = $this->apiOnlyNodeService();
        $attacker = User::factory()->customer()->create();

        $this->actingAs($attacker)->post(route('customer.services.container.domains.bind', $service), [
            'domain' => 'api.attacker.example',
            'purpose' => 'api',
        ])->assertForbidden();

        $this->assertDatabaseMissing('container_domains', ['domain' => 'api.attacker.example']);
    }

    public function test_admin_can_configure_api_hostname_for_customer_service(): void
    {
        [, $service] = $this->apiOnlyNodeService();
        $admin = User::factory()->admin()->create();
        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldReceive('bind')->once();
            $mock->shouldReceive('checkDns')->once()->andReturn(false);
            $mock->shouldReceive('enableSsl')->never();
        });
        $this->mock(DomainCloudflareDnsService::class, function ($mock) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->andReturn(null);
            $mock->shouldReceive('upsertARecord')->never();
        });

        $this->actingAs($admin)->post(route('admin.services.container.domains.bind', $service), [
            'domain' => 'api.example.com',
            'purpose' => 'api',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('container_domains', [
            'domain' => 'api.example.com',
            'purpose' => ContainerDomain::PURPOSE_API,
        ]);
    }

    public function test_removing_api_hostname_clears_client_endpoint_metadata(): void
    {
        [$user, $service, $deployment] = $this->apiOnlyNodeService([
            'api_hostname' => 'api.example.com',
            'api_url' => 'https://api.example.com',
        ]);
        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'api.example.com',
            'purpose' => ContainerDomain::PURPOSE_API,
            'status' => 'active',
        ]);
        $platform = Domain::create([
            'user_id' => $user->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-api',
        ]);
        $this->mock(NginxProxyService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('unbind')->once()->with(\Mockery::on(fn ($bound) => $bound->is($domain)))
                ->andReturnUsing(fn (ContainerDomain $bound) => $bound->delete());
        });
        $this->mock(DomainCloudflareDnsService::class, function ($mock) use ($platform) {
            $mock->shouldReceive('resolvePlatformDomainForHostname')->once()->andReturn($platform);
            $mock->shouldReceive('deleteARecordForHostname')
                ->once()
                ->with(\Mockery::on(fn ($domain) => $domain->is($platform)), 'api.example.com')
                ->andReturn(['success' => true, 'message' => 'deleted']);
        });

        $this->actingAs($user)
            ->delete(route('customer.services.container.domains.unbind', [$service, $domain]))
            ->assertSessionHas('success');

        $service->refresh();
        $this->assertArrayNotHasKey('api_hostname', $service->service_meta);
        $this->assertArrayNotHasKey('api_url', $service->service_meta);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => 'api_domain_unbound',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0: User, 1: Service, 2: ContainerDeployment}
     */
    private function apiOnlyNodeService(array $meta = []): array
    {
        $user = User::factory()->customer()->create();
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $node = Node::factory()->containerHost()->create(['ip_address' => '10.20.30.40']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'service_meta' => array_merge([
                'frontend' => 'none',
                'framework' => 'express',
            ], $meta),
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return [$user, $service->fresh(['product.containerTemplate', 'containerDeployment.node']), $deployment];
    }
}
