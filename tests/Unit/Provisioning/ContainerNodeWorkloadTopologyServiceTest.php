<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ApplicationRuntime;
use App\Services\Provisioning\ContainerApplicationRuntimeService;
use App\Services\Provisioning\ContainerNodeWorkloadTopologyService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerNodeWorkloadTopologyServiceTest extends TestCase
{
    #[Test]
    public function it_resolves_apps_api_and_apps_web_as_separate_workloads(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->twice()
            ->andReturnUsing(fn ($ssh, $host, $root, $port) => new ApplicationRuntime(
                ['sh', '-lc', "cd /app/{$root} && exec npm start"],
                'package-script',
                $root,
                '/app/'.$root,
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
                'engines' => ['node' => '>=20'],
            ],
            'apps/web' => [
                'scripts' => ['build' => 'vite build'],
                'dependencies' => ['react' => '^19.0'],
                'devDependencies' => ['vite' => '^7.0'],
                'engines' => ['node' => '>=22'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame('apps/web', $topology['frontend']['root']);
        $this->assertSame('>=22', $topology['frontend']['node_engine']);
        $this->assertSame('/app/apps/web', $topology['frontend']['working_directory']);
    }

    #[Test]
    public function it_deploys_the_api_when_the_only_other_app_is_expo(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->once()
            ->andReturn(new ApplicationRuntime(
                ['sh', '-lc', 'cd /app/apps/api && exec npm start'],
                'package-script',
                'apps/api',
                '/app/apps/api',
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('single', $topology['topology']);
        $this->assertSame('auto_api', $topology['selection_source']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame(['apps/mobile'], $topology['skipped_mobile']);
        $this->assertTrue(ContainerNodeWorkloadTopologyService::isApiOnly(
            $this->serviceWithTopology($topology, 'vite-spa')
        ));
    }

    #[Test]
    public function it_deploys_the_web_app_of_a_repository_that_also_ships_a_mobile_app(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->twice()
            ->andReturnUsing(fn ($ssh, $host, $root, $port) => new ApplicationRuntime(
                ['sh', '-lc', "cd /app/{$root} && exec npm start"],
                'package-script',
                $root,
                '/app/'.$root,
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            // Scanned before apps/web, and must not end the search.
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
            ],
            'apps/web' => [
                'scripts' => ['build' => 'vite build'],
                'devDependencies' => ['vite' => '^7.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame('apps/web', $topology['frontend']['root']);
    }

    #[Test]
    public function it_uses_a_next_app_when_vite_was_selected_but_the_repo_ships_next(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->twice()
            ->andReturnUsing(fn ($ssh, $host, $root, $port) => new ApplicationRuntime(
                ['sh', '-lc', "cd /app/{$root} && exec npm start"],
                'package-script',
                $root,
                '/app/'.$root,
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'packages/web' => [
                'scripts' => ['start' => 'next start'],
                'dependencies' => ['next' => '^15.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('packages/web', $topology['frontend']['root']);
        $this->assertSame('nextjs', $topology['frontend_type']);
    }

    #[Test]
    public function it_skips_a_stale_expo_frontend_pin_and_deploys_the_api(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->once()
            ->andReturn(new ApplicationRuntime(
                ['sh', '-lc', 'cd /app/apps/api && exec npm start'],
                'package-script',
                'apps/api',
                '/app/apps/api',
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
            frontendOverride: 'apps/mobile',
        );

        $this->assertSame('single', $topology['topology']);
        $this->assertSame('auto_api', $topology['selection_source']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame(['apps/mobile'], $topology['skipped_mobile']);
        $this->assertTrue(ContainerNodeWorkloadTopologyService::isApiOnly(
            $this->serviceWithTopology($topology, 'vite-spa')
        ));
    }

    #[Test]
    public function it_treats_expo_with_vite_as_mobile_not_a_browser_app(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->once()
            ->andReturn(new ApplicationRuntime(
                ['sh', '-lc', 'cd /app/apps/api && exec npm start'],
                'package-script',
                'apps/api',
                '/app/apps/api',
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81', 'vite' => '^6.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('auto_api', $topology['selection_source']);
        $this->assertSame(['apps/mobile'], $topology['skipped_mobile']);
    }

    #[Test]
    public function it_can_pin_a_vite_app_even_when_the_directory_is_named_mobile(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->twice()
            ->andReturnUsing(fn ($ssh, $host, $root, $port) => new ApplicationRuntime(
                ['sh', '-lc', "cd /app/{$root} && exec npm start"],
                'package-script',
                $root,
                '/app/'.$root,
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/mobile' => [
                'scripts' => ['build' => 'vite build'],
                'devDependencies' => ['vite' => '^7.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
            frontendOverride: 'apps/mobile',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('manual', $topology['selection_source']);
    }

    #[Test]
    public function it_skips_an_expo_pin_and_uses_the_browser_app_when_one_exists(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->twice()
            ->andReturnUsing(fn ($ssh, $host, $root, $port) => new ApplicationRuntime(
                ['sh', '-lc', "cd /app/{$root} && exec npm start"],
                'package-script',
                $root,
                '/app/'.$root,
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
            ],
            'apps/web' => [
                'scripts' => ['build' => 'vite build'],
                'devDependencies' => ['vite' => '^7.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
            frontendOverride: 'apps/mobile',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/web', $topology['frontend']['root']);
        $this->assertSame(['apps/mobile'], $topology['skipped_mobile']);
        $this->assertSame('auto', $topology['selection_source']);
    }

    #[Test]
    public function it_requires_an_override_when_multiple_frontends_match(): void
    {
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/web' => ['devDependencies' => ['vite' => '^7.0']],
            'frontend' => ['devDependencies' => ['vite' => '^7.0']],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Multiple frontend applications');
        (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );
    }

    #[Test]
    public function it_rejects_repository_path_traversal(): void
    {
        $this->expectException(\DomainException::class);
        (new ContainerNodeWorkloadTopologyService)->sanitizeRelativeRoot('../apps/api');
    }

    private function nodeService(string $framework, string $frontend): Service
    {
        $template = new ContainerTemplate(['slug' => 'nodejs', 'default_port' => 3000]);
        $service = new Service([
            'service_meta' => compact('framework', 'frontend'),
        ]);
        $service->setRelation('product', new Product);
        $service->product->setRelation('containerTemplate', $template);

        return $service;
    }

    /**
     * @param  array<string, mixed>  $topology
     */
    private function serviceWithTopology(array $topology, string $frontend): Service
    {
        $service = $this->nodeService('express', $frontend);
        $service->service_meta = array_merge($service->service_meta, [
            'frontend' => $frontend,
            'node_workloads' => $topology,
        ]);

        return $service;
    }

    /**
     * @param  array<string, array<string, mixed>>  $packages
     */
    private function sshForPackages(array $packages): SSHService
    {
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(function (string $command) use ($packages): string {
            foreach ($packages as $root => $package) {
                if (str_contains($command, '/'.$root.'/package.json')) {
                    return json_encode($package, JSON_THROW_ON_ERROR);
                }
            }

            return '';
        });

        return $ssh;
    }
}
