<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\NginxProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ContainerTerminationDomainUnbindTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminate_unbinds_all_container_domains_before_teardown(): void
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => null,
            'status' => 'running',
        ]);

        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'app.example.com',
            'status' => 'active',
        ]);

        $this->mock(NginxProxyService::class, function ($mock) {
            $mock->shouldNotReceive('unbind');
        });

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'unbindAllDomainsForService');
        $method->setAccessible(true);
        $method->invoke(app(ContainerDeploymentService::class), $service->fresh());

        $this->assertDatabaseMissing('container_domains', ['id' => $domain->id]);
    }

    public function test_terminate_calls_nginx_unbind_when_node_is_available(): void
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        $node = Node::factory()->containerHost()->create();
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $domain = ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => 'api.example.com',
            'status' => 'active',
        ]);

        $this->mock(NginxProxyService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('unbind')
                ->once()
                ->with(\Mockery::on(fn ($record) => $record->id === $domain->id));
        });

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'unbindAllDomainsForService');
        $method->setAccessible(true);
        $method->invoke(app(ContainerDeploymentService::class), $service->fresh());
    }
}
