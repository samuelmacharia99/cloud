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

    public function test_ssl_failure_keeps_bound_domain_active(): void
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
        ]);
        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'tajmaal.co.ke',
            'status' => 'active',
            'nginx_config_path' => '/etc/nginx/sites-enabled/tajmaal.co.ke.conf',
        ]);

        app(NginxProxyService::class)->recordSslFailure(
            $domain,
            new \RuntimeException('SSH command failed: certbot certonly')
        );

        $domain->refresh();

        $this->assertSame('active', $domain->status);
        $this->assertFalse($domain->ssl_enabled);
        $this->assertTrue($domain->canRequestSsl());
        $this->assertStringContainsString('certbot', (string) $domain->error_message);
    }

    public function test_customer_can_retry_ssl_after_previous_certbot_failure(): void
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
            'domain' => 'tajmaal.co.ke',
            'status' => 'failed',
            'ssl_enabled' => false,
            'nginx_config_path' => '/etc/nginx/sites-enabled/tajmaal.co.ke.conf',
            'error_message' => "SSH command failed: certbot certonly --nginx -d 'tajmaal.co.ke'\nType: unauthorized\nDetail: 88.99.104.138: Invalid response from http://tajmaal.co.ke/.well-known/acme-challenge/token: 404",
        ]);

        $this->mock(NginxProxyService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('enableSsl')
                ->once()
                ->with(\Mockery::on(fn ($record) => $record->id === $domain->id));
        });

        $this->actingAs($user)
            ->post(route('customer.services.container.domains.ssl', [$service, $domain]))
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'domains',
            ]))
            ->assertSessionHas('success');
    }

    public function test_customer_ssl_failure_flashes_human_readable_error(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $node = Node::factory()->containerHost()->create(['ip_address' => '10.20.30.40']);
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
            'domain' => 'tajmaal.co.ke',
            'status' => 'active',
        ]);

        $raw = "SSH command failed: certbot certonly --nginx -d 'tajmaal.co.ke'\nError: Command exited with status 1\nOutput: Type: unauthorized Detail: 88.99.104.138: Invalid response from http://tajmaal.co.ke/.well-known/acme-challenge/token: 404";

        $this->mock(NginxProxyService::class, function ($mock) use ($raw) {
            $mock->shouldReceive('enableSsl')->once()->andThrow(new \RuntimeException($raw));
        });

        $this->actingAs($user)
            ->post(route('customer.services.container.domains.ssl', [$service, $domain]))
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'domains',
            ]))
            ->assertSessionHasErrors('error');

        $flash = session('errors')->first('error');
        $this->assertStringContainsString('could not verify tajmaal.co.ke', strtolower($flash));
        $this->assertStringContainsString('88.99.104.138', $flash);
        $this->assertStringContainsString('10.20.30.40', $flash);
        $this->assertStringNotContainsString('SSH command failed', $flash);
    }
}
