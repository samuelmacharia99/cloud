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
    public function it_hosts_expo_web_when_it_is_the_only_frontend(): void
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
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('auto', $topology['selection_source']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('expo-web', $topology['frontend_type']);
        $this->assertSame([], $topology['skipped_mobile']);
        $this->assertFalse(ContainerNodeWorkloadTopologyService::isApiOnly(
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
    public function it_hosts_an_explicit_expo_frontend_at_apps_mobile(): void
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
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
            frontendOverride: 'apps/mobile',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('expo-web', $topology['frontend_type']);
        $this->assertSame([], $topology['skipped_mobile']);
    }

    #[Test]
    public function it_treats_expo_with_vite_as_a_browser_app(): void
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
                'scripts' => ['start' => 'expo start', 'build' => 'vite build'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81', 'vite' => '^6.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('vite-spa', $topology['frontend_type']);
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
    public function it_keeps_an_expo_frontend_pin_even_when_a_web_app_also_exists(): void
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
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('expo-web', $topology['frontend_type']);
        $this->assertSame([], $topology['skipped_mobile']);
        $this->assertSame('manual', $topology['selection_source']);
    }

    #[Test]
    public function it_hosts_expo_web_when_the_frontend_pin_collides_with_the_api(): void
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
                'scripts' => ['start' => 'node server.js', 'build' => 'vite build'],
                'dependencies' => ['express' => '^5.0', 'vite' => '^6.0'],
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
            backendOverride: 'apps/api',
            frontendOverride: 'apps/api',
        );

        $this->assertSame('split_web_api', $topology['topology']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('expo-web', $topology['frontend_type']);
        $this->assertSame([], $topology['skipped_mobile']);
    }

    #[Test]
    public function it_pins_a_project_web_container_to_expo_and_ignores_the_api(): void
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
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'next start', 'build' => 'next build'],
                'dependencies' => ['next' => '15.5.25'],
            ],
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
            ],
        ]);

        $service = $this->nodeService('other', 'none');
        $service->service_meta = array_merge($service->service_meta ?? [], [
            'project_role' => 'frontend',
            'node_project_root' => 'apps/mobile',
            'node_backend_root' => 'apps/api',
            'frontend' => 'none',
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $service,
            $ssh,
            '/srv/app',
            backendOverride: 'apps/api',
        );

        $this->assertSame('single', $topology['topology']);
        $this->assertSame('project_role', $topology['selection_source']);
        $this->assertSame('apps/mobile', $topology['backend']['root']);
        $this->assertSame('/app/apps/mobile', $topology['backend']['working_directory']);
        $this->assertStringContainsString('apps/mobile', implode(' ', $topology['notes']));
        $this->assertStringNotContainsString('apps/api', implode(' ', $topology['notes']));
    }

    #[Test]
    public function it_keeps_a_project_role_pin_when_retry_roots_are_blank(): void
    {
        $meta = [
            'project_role' => 'frontend',
            'frontend' => 'none',
            'node_backend_root' => 'apps/mobile',
            'node_project_root' => 'apps/mobile',
        ];

        $updated = (new ContainerNodeWorkloadTopologyService)->applyOperatorRootSelection($meta, [
            'backend_root' => '',
            'frontend_root' => '',
        ]);

        $this->assertSame('apps/mobile', $updated['node_backend_root']);
        $this->assertSame('apps/mobile', $updated['node_project_root']);
        $this->assertArrayNotHasKey('node_frontend_root', $updated);
        $this->assertArrayNotHasKey('node_workloads', $updated);
    }

    #[Test]
    public function it_runs_the_api_alone_when_there_is_no_separate_frontend(): void
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
                'scripts' => ['start' => 'node server.js', 'build' => 'vite build'],
                'dependencies' => ['express' => '^5.0', 'vite' => '^6.0'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
            backendOverride: 'apps/api',
            frontendOverride: 'apps/api',
        );

        $this->assertSame('single', $topology['topology']);
        $this->assertSame('auto_api', $topology['selection_source']);
        $this->assertSame('apps/api', $topology['backend']['root']);
        $this->assertSame([], $topology['skipped_mobile']);
        $this->assertNotEmpty($topology['notes']);
    }

    #[Test]
    public function it_runs_a_pinned_expo_app_as_the_only_container_process(): void
    {
        $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
        $runtime->shouldReceive('detectNodeRuntimeAt')
            ->once()
            ->andReturn(new ApplicationRuntime(
                ['sh', '-lc', 'cd /app/apps/mobile && exec npm start'],
                'expo-web',
                'Expo web export',
                '/app/apps/mobile',
            ));
        $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);
        $ssh = $this->sshForPackages([
            'apps/mobile' => [
                'scripts' => ['start' => 'expo start'],
                'dependencies' => ['expo' => '^54.0', 'react-native' => '^0.81'],
            ],
        ]);

        $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('other', 'none'),
            $ssh,
            '/srv/app',
            backendOverride: 'apps/mobile',
        );

        $this->assertSame('single', $topology['topology']);
        $this->assertSame('apps/mobile', $topology['backend']['root']);
        $this->assertSame([], $topology['skipped_mobile']);
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

    #[Test]
    public function it_resolves_python_ruby_and_go_backends_with_node_frontends(): void
    {
        foreach ([
            'python' => ['framework' => 'django', 'marker' => 'manage.py', 'source' => 'django'],
            'ruby' => ['framework' => 'rails', 'marker' => 'bin/rails', 'source' => 'rails'],
            'go' => ['framework' => 'other', 'marker' => 'go.mod', 'source' => 'entrypoint'],
        ] as $slug => $case) {
            $runtime = Mockery::mock(ContainerApplicationRuntimeService::class);
            $runtime->shouldReceive('detectRuntimeAt')
                ->once()
                ->withArgs(fn ($ssh, $host, $root, $runtimeSlug, $port) => $host === '/srv/app'
                    && $root === 'backend'
                    && $runtimeSlug === $slug
                    && $port === ContainerNodeWorkloadTopologyService::BACKEND_PORT)
                ->andReturn(new ApplicationRuntime(
                    ['sh', '-lc', 'cd /app/backend && exec backend-server'],
                    $case['source'],
                    ucfirst($slug).' backend',
                    '/app/backend',
                ));
            $runtime->shouldReceive('detectNodeRuntimeAt')
                ->once()
                ->andReturn(new ApplicationRuntime(
                    ['sh', '-lc', 'cd /app/frontend && exec npm start'],
                    'vite',
                    'Vite frontend',
                    '/app/frontend',
                ));
            $this->app->instance(ContainerApplicationRuntimeService::class, $runtime);

            $ssh = $this->sshForSplitBackend($case['marker']);
            $topology = (new ContainerNodeWorkloadTopologyService)->resolve(
                $this->runtimeService($slug, $case['framework'], 'vite-spa'),
                $ssh,
                '/srv/app',
            );

            $this->assertSame('split_web_api', $topology['topology']);
            $this->assertSame($slug, $topology['backend_slug']);
            $this->assertSame('backend', $topology['backend']['root']);
            $this->assertSame('/app/backend', $topology['backend']['working_directory']);
            $this->assertSame('frontend', $topology['frontend']['root']);
            $this->assertSame('vite-spa', $topology['frontend_type']);
        }
    }

    private function nodeService(string $framework, string $frontend): Service
    {
        return $this->runtimeService('nodejs', $framework, $frontend);
    }

    private function runtimeService(string $slug, string $framework, string $frontend): Service
    {
        $template = new ContainerTemplate(['slug' => $slug, 'default_port' => 3000]);
        $service = new Service([
            'service_meta' => compact('framework', 'frontend'),
        ]);
        $service->setRelation('product', new Product);
        $service->product->setRelation('containerTemplate', $template);

        return $service;
    }

    private function sshForSplitBackend(string $marker): SSHService
    {
        $frontend = json_encode([
            'scripts' => ['build' => 'vite build'],
            'devDependencies' => ['vite' => '^7.0'],
        ], JSON_THROW_ON_ERROR);
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(function (string $command) use ($frontend, $marker): string {
            if (str_contains($command, '-type f -name package.json')) {
                return '/srv/app/frontend/package.json';
            }
            if (str_contains($command, '-name manage.py') && str_contains($command, '-name go.mod')) {
                return '/srv/app/backend/'.$marker;
            }
            if (str_contains($command, '/srv/app/frontend/package.json')) {
                return $frontend;
            }
            if (str_contains($command, '/backend/'.$marker)) {
                return 'yes';
            }

            return str_contains($command, '&& echo yes || echo no') ? 'no' : '';
        });

        return $ssh;
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
