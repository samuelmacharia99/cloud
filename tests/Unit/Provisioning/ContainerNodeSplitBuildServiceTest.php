<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ApplicationRuntime;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerApplicationRuntimeService;
use App\Services\Provisioning\ContainerNodeBuildService;
use App\Services\Provisioning\ContainerNodeWorkloadTopologyService;
use App\Services\Provisioning\ContainerStackCommandService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerNodeSplitBuildServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_and_records_both_workloads_in_one_release_manifest(): void
    {
        [$service, $deployment] = $this->models();
        $topology = [
            'schema' => 1,
            'topology' => 'split_web_api',
            'frontend_type' => 'vite-spa',
            'backend' => ['root' => 'apps/api', 'port' => 8000],
            'frontend' => ['root' => 'apps/mobile', 'port' => 3000],
        ];
        $topologyService = Mockery::mock(ContainerNodeWorkloadTopologyService::class);
        $topologyService->shouldReceive('resolve')->once()->andReturn($topology);
        $topologyService->shouldReceive('persist')->once();
        $this->app->instance(ContainerNodeWorkloadTopologyService::class, $topologyService);

        $commands = Mockery::mock(ContainerStackCommandService::class);
        $commands->shouldReceive('buildNodeApplication')
            ->twice()
            ->andReturnUsing(fn ($service, $deployment, $ssh, $force, $root) => ["built {$root}"]);
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->twice()
            ->andReturnUsing(fn ($ssh, $host, $root, $port) => new ApplicationRuntime(
                ['sh', '-lc', "cd /app/{$root} && npm start"],
                'package-script',
                $root,
                '/app/'.$root,
            ));
        $runtime->shouldReceive('packageJsonRequiresProductionBuild')->twice()->andReturn(false);
        $runtime->shouldReceive('resolveNodePackageManager')->twice()->andReturn('npm');
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(function (string $command): string {
            if (str_contains($command, 'package.json')) {
                return '{"scripts":{"start":"node index.js"},"engines":{"node":">=20"}}';
            }
            if (str_contains($command, 'rev-parse')) {
                return 'abc123';
            }

            return '';
        });
        $ssh->method('mkdirp');
        $uploadedManifest = null;
        $ssh->method('upload')->willReturnCallback(function (string $contents, string $path) use (&$uploadedManifest): void {
            if (str_contains($path, 'release.json.tmp-')) {
                $uploadedManifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            }
        });

        $result = (new ContainerNodeBuildService(
            $commands,
            $runtime,
            new ContainerAppDirectoryService,
        ))->build($service, $deployment, $ssh, operationAlreadyLocked: true);

        $this->assertSame(2, $result['manifest']['schema']);
        $this->assertSame('apps/api', $result['manifest']['workloads']['backend']['root']);
        $this->assertSame('apps/mobile', $result['manifest']['workloads']['frontend']['root']);
        $this->assertSame('split_web_api', $uploadedManifest['topology']);
        $this->assertDatabaseHas('container_deployment_events', [
            'service_id' => $service->id,
            'event' => 'node_split_build_succeeded',
        ]);
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function models(): array
    {
        $template = ContainerTemplate::factory()->create([
            'slug' => 'nodejs',
            'hosting_type' => 'container',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $user = User::factory()->customer()->create();
        $node = Node::factory()->create(['type' => 'container_host', 'is_active' => true]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'service_meta' => [
                'framework' => 'express',
                'frontend' => 'vite-spa',
                'node_version_source' => 'manual',
            ],
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'split-build-'.$service->id,
            'selected_version' => '22-alpine',
            'env_values' => ['VITE_API_URL' => '/api'],
            'status' => 'running',
        ]);

        return [$service->fresh(['product.containerTemplate']), $deployment];
    }
}
