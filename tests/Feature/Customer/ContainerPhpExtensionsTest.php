<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerPhpExtensionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerPhpExtensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_extensions_tab_uses_toggles_instead_of_a_save_button(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->mock(ContainerDeploymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getStatus')->andReturn([
                'status' => 'running',
                'healthy' => true,
            ]);
        });

        $this->mock(ContainerPhpExtensionsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('supportsTemplate')->andReturn(true);
            $mock->shouldReceive('buildPanelState')->andReturn([
                'available' => true,
                'container_running' => true,
                'builtin' => [
                    ['key' => 'mbstring', 'label' => 'MBSTRING', 'installed' => true],
                ],
                'optional' => [
                    [
                        'key' => 'redis',
                        'label' => 'Redis',
                        'description' => 'Redis cache and queue client (PECL).',
                        'enabled' => false,
                        'installed' => false,
                    ],
                ],
            ]);
        });

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', [
                'service' => $service,
                'tab' => 'php-extensions',
            ]))
            ->assertOk()
            ->assertSee('PHP Extensions')
            ->assertSee('Toggle an optional PHP extension')
            ->assertSee('role="switch"', false)
            ->assertSee('toggleExtension(extension)', false)
            ->assertDontSee('Save extensions');
    }

    public function test_customer_can_enable_an_extension_and_it_installs_immediately(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->mock(ContainerPhpExtensionsService::class, function (MockInterface $mock) use ($service) {
            $mock->shouldReceive('supportsTemplate')->andReturn(true);
            $mock->shouldReceive('toggle')
                ->once()
                ->withArgs(function ($svc, $deployment, $key, $enabled) use ($service) {
                    return $svc->is($service)
                        && $deployment->is($service->containerDeployment)
                        && $key === 'redis'
                        && $enabled === true;
                })
                ->andReturn([
                    'message' => 'PHP extension enabled: Redis. Restart the container if your app still reports a missing extension.',
                    'extension' => [
                        'key' => 'redis',
                        'enabled' => true,
                        'installed' => true,
                    ],
                ]);
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.php-extensions.update', $service), [
                'extension' => 'redis',
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('extension.key', 'redis')
            ->assertJsonPath('extension.enabled', true)
            ->assertJsonPath('extension.installed', true)
            ->assertJsonPath('message', 'PHP extension enabled: Redis. Restart the container if your app still reports a missing extension.');
    }

    public function test_customer_can_disable_an_extension_preference(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->mock(ContainerPhpExtensionsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('supportsTemplate')->andReturn(true);
            $mock->shouldReceive('toggle')
                ->once()
                ->withArgs(fn ($svc, $deployment, $key, $enabled) => $key === 'redis' && $enabled === false)
                ->andReturn([
                    'message' => 'Redis was removed from your saved extensions. It remains in the runtime until the container is rebuilt.',
                    'extension' => [
                        'key' => 'redis',
                        'enabled' => false,
                    ],
                ]);
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.php-extensions.update', $service), [
                'extension' => 'redis',
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('extension.enabled', false);
    }

    public function test_enabling_is_rejected_when_the_container_is_not_running(): void
    {
        [$customer, $service] = $this->makeLaravelService('stopped');

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.php-extensions.update', $service), [
                'extension' => 'redis',
                'enabled' => true,
            ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'Start the app before enabling PHP extensions.');
    }

    public function test_unknown_extension_is_rejected(): void
    {
        [$customer, $service] = $this->makeLaravelService();

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.php-extensions.update', $service), [
                'extension' => 'not-a-real-ext',
                'enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension');
    }

    public function test_other_customers_cannot_toggle_php_extensions(): void
    {
        [, $service] = $this->makeLaravelService();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->postJson(route('customer.services.container.php-extensions.update', $service), [
                'extension' => 'redis',
                'enabled' => true,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function makeLaravelService(string $deploymentStatus = 'running'): array
    {
        $customer = User::factory()->customer()->create();
        $template = ContainerTemplate::query()->firstOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel app',
                'category' => 'web',
                'docker_image' => 'php:8.3',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 2,
                'is_active' => true,
                'order' => 0,
                'hosting_type' => 'container',
            ]
        );
        $template->forceFill([
            'hosting_type' => 'container',
            'is_active' => true,
            'name' => 'Laravel',
        ])->save();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'name' => 'Laravel App',
        ]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'ssh_username' => 'root',
            'ssh_password' => 'secret',
            'is_active' => true,
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => $deploymentStatus,
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-laravel',
        ]);

        return [$customer, $service->fresh(['product.containerTemplate', 'containerDeployment.node'])];
    }
}
