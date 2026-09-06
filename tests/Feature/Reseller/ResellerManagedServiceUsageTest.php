<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerManagedServiceUsageTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter-'.uniqid(),
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_services' => 50,
            'disk_pool_gb' => 100,
            'max_users' => 10,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now(),
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_reseller_sees_allocated_specs_and_latest_usage_on_container_service(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Usage Customer',
        ]);
        $product = Product::factory()->containerHosting()->create([
            'name' => 'App Plan',
            'resource_limits' => [
                'cpu' => 2,
                'memory' => 4096,
                'disk' => 40,
                'bandwidth_gb' => 200,
            ],
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'name' => 'Shop Container',
            'status' => 'active',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
        ]);
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 18.5,
            'memory_used_mb' => 1024,
            'disk_used_gb' => 11.2,
            'net_io_rx_bytes' => 0,
            'net_io_tx_bytes' => 0,
            'recorded_at' => now()->subHours(2),
        ]);
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 22,
            'memory_used_mb' => 1536,
            'disk_used_gb' => 12.8,
            'net_io_rx_bytes' => 2 * 1024 * 1024 * 1024,
            'net_io_tx_bytes' => 512 * 1024 * 1024,
            'recorded_at' => now()->subHour(),
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.services.index'))
            ->assertOk()
            ->assertSee('Shop Container')
            ->assertSee(route('reseller.customers.show', $customer), false)
            ->assertSee('2 CPU')
            ->assertSee('4 GB RAM')
            ->assertSee('40 GB disk')
            ->assertSee('12.8')
            ->assertSee('2.50 GB');

        $this->actingAs($reseller)
            ->get(route('reseller.services.show', $service))
            ->assertOk()
            ->assertSee('Plan and usage')
            ->assertSee('2 cores')
            ->assertSee('12.8')
            ->assertSee('/ 40 GB')
            ->assertSee('2.50 GB')
            ->assertSee('/ 200 GB')
            ->assertSee('customer portal')
            ->assertDontSee(route('customer.services.container.start', $service), false)
            ->assertDontSee(route('customer.services.container.logs', $service), false);
    }

    public function test_reseller_container_show_without_metrics_still_lists_plan_specs(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->containerHosting()->create([
            'resource_limits' => [
                'cpu' => 1,
                'memory' => 2048,
                'disk' => 20,
                'bandwidth_gb' => 50,
            ],
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'name' => 'Empty Metrics App',
            'status' => 'active',
        ]);
        ContainerDeployment::factory()->create(['service_id' => $service->id]);

        $this->actingAs($reseller)
            ->get(route('reseller.services.index'))
            ->assertOk()
            ->assertSee('No sample yet');

        $this->actingAs($reseller)
            ->get(route('reseller.services.show', $service))
            ->assertOk()
            ->assertSee('20 GB included')
            ->assertSee('50 GB included');
    }
}
