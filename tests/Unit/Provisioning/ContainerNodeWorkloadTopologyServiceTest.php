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
    public function it_resolves_apps_api_and_browser_mobile_as_separate_workloads(): void
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
            'apps/mobile' => [
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
        $this->assertSame('apps/mobile', $topology['frontend']['root']);
        $this->assertSame('>=22', $topology['frontend']['node_engine']);
        $this->assertSame('/app/apps/mobile', $topology['frontend']['working_directory']);
    }

    #[Test]
    public function it_rejects_expo_as_a_browser_frontend(): void
    {
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

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Expo/React Native');
        (new ContainerNodeWorkloadTopologyService)->resolve(
            $this->nodeService('express', 'vite-spa'),
            $ssh,
            '/srv/app',
        );
    }

    #[Test]
    public function it_requires_an_override_when_multiple_frontends_match(): void
    {
        $ssh = $this->sshForPackages([
            'apps/api' => [
                'scripts' => ['start' => 'node server.js'],
                'dependencies' => ['express' => '^5.0'],
            ],
            'apps/mobile' => ['devDependencies' => ['vite' => '^7.0']],
            'apps/web' => ['devDependencies' => ['vite' => '^7.0']],
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
        $template = new ContainerTemplate(['slug' => 'nodejs']);
        $service = new Service([
            'service_meta' => compact('framework', 'frontend'),
        ]);
        $service->setRelation('product', new Product);
        $service->product->setRelation('containerTemplate', $template);

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
