<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ApplicationRuntime;
use App\Services\Provisioning\ContainerApplicationRuntimeService;
use App\Services\Provisioning\ContainerExclusiveApplicationPin;
use App\Services\Provisioning\ContainerNodeWorkloadTopologyService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ContainerExclusiveApplicationPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_comes_from_project_role_not_the_service_name(): void
    {
        $pin = new ContainerExclusiveApplicationPin;
        $web = $this->makeService([
            'name' => 'tier-3-web',
            'service_meta' => ['frontend' => 'none'],
        ]);

        $this->assertNull($pin->role($web));

        $web->service_meta = ['project_role' => 'frontend', 'frontend' => 'none'];
        $this->assertSame('frontend', $pin->role($web));
    }

    public function test_role_comes_from_sibling_ids_when_project_role_is_missing(): void
    {
        $pin = new ContainerExclusiveApplicationPin;
        $api = $this->makeService(['service_meta' => []]);
        $web = $this->makeService([
            'service_meta' => [
                'backend_service_id' => $api->id,
                'frontend_service_id' => 0,
            ],
        ]);
        $api->update([
            'service_meta' => [
                'frontend_service_id' => $web->id,
                'backend_service_id' => $api->id,
            ],
        ]);
        $web->refresh();
        $api->refresh();

        $this->assertSame('frontend', $pin->role($web));
        $this->assertSame('backend', $pin->role($api));
    }

    public function test_stored_root_prefers_the_application_pin_over_a_stale_backend_alias(): void
    {
        $pin = new ContainerExclusiveApplicationPin;
        $service = $this->makeService([
            'service_meta' => [
                'project_role' => 'frontend',
                'node_application_root' => 'apps/mobile',
                'node_backend_root' => 'apps/api',
                'sibling_application_root' => 'apps/api',
            ],
        ]);

        $stored = $pin->storedApplicationRoot($service, 'apps/api');

        $this->assertSame('found', $stored['state']);
        $this->assertSame('apps/mobile', $stored['root']);
    }

    public function test_a_pin_that_equals_the_sibling_api_is_a_conflict(): void
    {
        $pin = new ContainerExclusiveApplicationPin;
        $service = $this->makeService([
            'service_meta' => [
                'project_role' => 'frontend',
                'node_backend_root' => 'apps/api',
                'sibling_application_root' => 'apps/api',
            ],
        ]);

        $stored = $pin->storedApplicationRoot($service, 'apps/api');

        $this->assertSame('conflict', $stored['state']);
        $this->assertSame('apps/api', $stored['rejected']);
        $this->assertNull($stored['root']);
    }

    public function test_a_corrupted_web_pin_is_restored_from_the_api_sibling_ledger(): void
    {
        $pin = new ContainerExclusiveApplicationPin;
        $api = $this->makeService([
            'service_meta' => [
                'project_role' => 'backend',
                'node_application_root' => 'apps/api',
                'node_backend_root' => 'apps/api',
                'sibling_application_root' => 'apps/mobile',
            ],
        ]);
        $web = $this->makeService([
            'service_meta' => [
                'project_role' => 'frontend',
                'backend_service_id' => $api->id,
                'node_backend_root' => 'apps/api',
            ],
        ]);
        $api->update([
            'service_meta' => array_merge($api->service_meta, [
                'frontend_service_id' => $web->id,
            ]),
        ]);

        $stored = $pin->storedApplicationRoot($web->fresh());

        $this->assertSame('found', $stored['state']);
        $this->assertSame('apps/mobile', $stored['root']);
    }

    public function test_topology_builds_the_restored_expo_root_not_the_api(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->once()
            ->withArgs(fn ($ssh, $host, $root): bool => $root === 'apps/mobile')
            ->andReturn(new ApplicationRuntime(
                ['sh', '-lc', 'cd /app/apps/mobile && exec npx serve dist'],
                'expo-web',
                'apps/mobile',
                '/app/apps/mobile',
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);

        $api = $this->makeService([
            'service_meta' => [
                'project_role' => 'backend',
                'node_application_root' => 'apps/api',
                'node_backend_root' => 'apps/api',
                'sibling_application_root' => 'apps/mobile',
            ],
        ]);
        $web = $this->makeService([
            'service_meta' => [
                'project_role' => 'frontend',
                'frontend' => 'none',
                'backend_service_id' => $api->id,
                'node_backend_root' => 'apps/api',
            ],
        ]);
        $api->update([
            'service_meta' => array_merge($api->service_meta, [
                'frontend_service_id' => $web->id,
            ]),
        ]);

        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(function (string $command): string {
            if (str_contains($command, '/apps/mobile/package.json')) {
                return json_encode([
                    'scripts' => ['start' => 'expo start'],
                    'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
                ], JSON_THROW_ON_ERROR);
            }

            return '';
        });

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $web->fresh(),
            $ssh,
            '/srv/app',
            backendOverride: 'apps/api',
        );

        $this->assertSame('apps/mobile', $topology['backend']['root']);
        $this->assertSame('project_role', $topology['selection_source']);
    }

    public function test_blank_retry_roots_keep_the_exclusive_application_pin(): void
    {
        $updated = (new ContainerExclusiveApplicationPin)->applyOperatorRootSelection([
            'project_role' => 'frontend',
            'frontend' => 'none',
            'node_application_root' => 'apps/mobile',
            'node_backend_root' => 'apps/mobile',
            'node_project_root' => 'apps/mobile',
            'sibling_application_root' => 'apps/api',
        ], [
            'backend_root' => '',
            'frontend_root' => '',
        ]);

        $this->assertSame('apps/mobile', $updated['node_application_root']);
        $this->assertSame('apps/mobile', $updated['node_backend_root']);
        $this->assertSame('apps/api', $updated['sibling_application_root']);
        $this->assertArrayNotHasKey('node_frontend_root', $updated);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeService(array $attributes): Service
    {
        $template = ContainerTemplate::query()->where('slug', 'nodejs')->first()
            ?? ContainerTemplate::factory()->create([
                'slug' => 'nodejs',
                'default_port' => 3000,
                'is_active' => true,
            ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);

        return Service::factory()->create(array_merge([
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ], $attributes));
    }
}
