<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerDomainSslTest extends TestCase
{
    use RefreshDatabase;

    public function test_domains_tab_shows_human_ssl_error_and_retry_button(): void
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
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'tajmaal.co.ke',
            'status' => 'failed',
            'ssl_enabled' => false,
            'nginx_config_path' => '/etc/nginx/sites-enabled/tajmaal.co.ke.conf',
            'error_message' => "SSH command failed: certbot certonly --nginx -d 'tajmaal.co.ke' --non-interactive --agree-tos --email 'admin@talksasa.cloud' --redirect 2>&1 Error: Command exited with status 1 Output: Type: unauthorized Detail: 88.99.104.138: Invalid response from http://tajmaal.co.ke/.well-known/acme-challenge/token: 404",
        ]);

        $this->mock(ContainerDeploymentService::class, function ($mock) {
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->actingAs($user)
            ->get(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'domains',
            ]))
            ->assertOk()
            ->assertSee('Let’s Encrypt could not verify tajmaal.co.ke.', false)
            ->assertSee('88.99.104.138')
            ->assertSee('10.20.30.40')
            ->assertSee('Retry SSL')
            ->assertSee('Technical details');
    }
}
