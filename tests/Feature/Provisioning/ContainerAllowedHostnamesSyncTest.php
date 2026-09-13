<?php

namespace Tests\Feature\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerAllowedHostnamesResolver;
use App\Services\Provisioning\ContainerAllowedHostnamesSync;
use App\Services\Provisioning\ContainerEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Binding or removing a domain must reach the running application's Host
 * allow-list, and only then: no restart when nothing changed, nothing at all
 * for stacks that do not validate the Host header.
 */
class ContainerAllowedHostnamesSyncTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_changed_allow_list_is_applied_to_the_running_stack(): void
    {
        [$service] = $this->deployedService('ospos', ['ALLOWED_HOSTNAMES' => 'localhost,127.0.0.1']);
        ContainerDomain::query()->create([
            'container_deployment_id' => $service->containerDeployment->id,
            'domain' => 'shop.example.com',
            'purpose' => ContainerDomain::PURPOSE_WEB,
            'status' => 'active',
        ]);

        $environment = Mockery::mock(ContainerEnvironmentService::class);
        $environment->shouldReceive('updateVariables')
            ->once()
            ->withArgs(fn (Service $s, array $vars, bool $restart) => $s->is($service)
                && $vars === ['ALLOWED_HOSTNAMES' => 'shop.example.com,localhost,127.0.0.1']
                && $restart === true)
            ->andReturn(['updated' => 1, 'skipped' => [], 'applied' => true, 'message' => 'ok']);

        $sync = new ContainerAllowedHostnamesSync(new ContainerAllowedHostnamesResolver(fn () => null), $environment);
        $result = $sync->sync($service->fresh());

        $this->assertTrue($result['changed']);
        $this->assertSame('shop.example.com,localhost,127.0.0.1', $result['value']);
    }

    #[Test]
    public function an_unchanged_allow_list_does_not_touch_the_stack(): void
    {
        [$service] = $this->deployedService('ospos', ['ALLOWED_HOSTNAMES' => 'localhost,127.0.0.1']);

        $environment = Mockery::mock(ContainerEnvironmentService::class);
        $environment->shouldNotReceive('updateVariables');

        $sync = new ContainerAllowedHostnamesSync(new ContainerAllowedHostnamesResolver(fn () => null), $environment);

        $this->assertFalse($sync->sync($service->fresh())['changed']);
    }

    #[Test]
    public function stacks_without_a_hostname_key_are_ignored(): void
    {
        [$service] = $this->deployedService('wordpress', []);

        $environment = Mockery::mock(ContainerEnvironmentService::class);
        $environment->shouldNotReceive('updateVariables');

        $sync = new ContainerAllowedHostnamesSync(new ContainerAllowedHostnamesResolver(fn () => null), $environment);
        $result = $sync->sync($service->fresh());

        $this->assertFalse($result['changed']);
        $this->assertNull($result['key']);
    }

    #[Test]
    public function a_failure_to_apply_is_logged_and_swallowed_by_the_quiet_variant(): void
    {
        [$service] = $this->deployedService('ospos', ['ALLOWED_HOSTNAMES' => 'stale']);

        $environment = Mockery::mock(ContainerEnvironmentService::class);
        $environment->shouldReceive('updateVariables')->once()->andThrow(new \RuntimeException('node unreachable'));

        $sync = new ContainerAllowedHostnamesSync(new ContainerAllowedHostnamesResolver(fn () => null), $environment);
        $sync->syncQuietly($service->fresh(), 'test');

        $this->assertTrue(true, 'the caller was not interrupted');
    }

    /**
     * @param  array<string, string>  $env
     * @return array{0: Service}
     */
    private function deployedService(string $slug, array $env): array
    {
        $customer = User::factory()->customer()->create();
        // Catalog migrations already seed ospos; wordpress is seeded by the base seeder only.
        $template = ContainerTemplate::query()->where('slug', $slug)->first()
            ?? ContainerTemplate::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true, 'hosting_type' => 'container']);
        $product = Product::factory()->containerHosting()->create(['container_template_id' => $template->id]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::create([
            'service_id' => $service->id,
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-'.$slug,
            'status' => 'running',
            'env_values' => $env,
        ]);

        return [$service->fresh(['containerDeployment', 'product.containerTemplate'])];
    }
}
