<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerEnvironmentUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function containerServiceFor(User $customer, string $status = 'running'): Service
    {
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'service_meta' => [
                'env_values' => [
                    'API_URL' => 'http://127.0.0.1:8001',
                ],
            ],
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'status' => $status,
            'env_values' => [
                'API_URL' => 'http://127.0.0.1:8001',
            ],
        ]);

        return $service->fresh(['containerDeployment', 'product']);
    }

    public function test_customer_can_save_environment_variables(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer);

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('applyEnvironmentVariables')->once();
        });

        $this->actingAs($customer)
            ->put(route('customer.services.container.environment.update', $service), [
                'variables' => [
                    ['key' => 'API_URL', 'value' => 'https://atlas.car-washflow.com/api/v1'],
                    ['key' => 'FRONTEND_URL', 'value' => 'https://atlas.car-washflow.com'],
                ],
                'restart' => '1',
            ])
            ->assertRedirect(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'environment',
            ]))
            ->assertSessionHas('success');

        $deployment = $service->fresh()->containerDeployment;
        $this->assertSame('https://atlas.car-washflow.com/api/v1', $deployment->env_values['API_URL']);
        $this->assertSame('https://atlas.car-washflow.com', $deployment->env_values['FRONTEND_URL']);
    }

    public function test_customer_can_save_environment_while_deploying(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer, 'deploying');

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('applyEnvironmentVariables')->once();
        });

        $this->actingAs($customer)
            ->put(route('customer.services.container.environment.update', $service), [
                'variables' => [
                    ['key' => 'FRONTEND_URL', 'value' => 'https://atlas.car-washflow.com'],
                ],
                'restart' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            'https://atlas.car-washflow.com',
            $service->fresh()->containerDeployment->env_values['FRONTEND_URL']
        );
    }

    public function test_saved_values_remain_when_apply_fails(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer);

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('applyEnvironmentVariables')
                ->once()
                ->andThrow(new \RuntimeException('SSH timeout'));
        });

        $this->actingAs($customer)
            ->put(route('customer.services.container.environment.update', $service), [
                'variables' => [
                    ['key' => 'FRONTEND_URL', 'value' => 'https://atlas.example.com'],
                ],
                'restart' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHasErrors('error');

        $this->assertSame(
            'https://atlas.example.com',
            $service->fresh()->containerDeployment->env_values['FRONTEND_URL']
        );
    }
}
