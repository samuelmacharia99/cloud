<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ApplicationRuntime;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\NodeWebGatewayProxy;
use App\Services\SSH\SSHService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class NodeWebGatewayProxyTest extends TestCase
{
    #[Test]
    public function gateway_routes_api_and_websocket_paths_to_backend_and_pages_to_frontend(): void
    {
        $script = NodeWebGatewayProxy::scriptContents();

        $this->assertStringContainsString("hasPrefix('/api')", $script);
        $this->assertStringContainsString("hasPrefix('/socket.io')", $script);
        $this->assertStringContainsString("'x-talksasa-upstream': role", $script);
        $this->assertStringContainsString("role: 'frontend'", $script);
        $this->assertStringContainsString("server.on('upgrade'", $script);
    }

    #[Test]
    public function compose_exposes_only_edge_for_a_split_node_vite_stack(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'nodejs',
            'docker_image' => 'node:22-alpine',
            'default_port' => 3000,
            'required_cpu_cores' => 1,
            'required_ram_mb' => 1024,
            'volume_paths' => ['app_data' => '/app'],
        ]);
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-2-nodejs',
            'assigned_port' => 30123,
            'restart_policy' => 'always',
            'cpu_limit' => 1,
            'memory_limit_mb' => 1024,
            'selected_version' => '22-alpine',
        ]);
        $runtime = new ApplicationRuntime(
            ['sh', '-lc', 'cd /app/apps/api && exec npm start'],
            'package-script',
            'npm start',
            '/app/apps/api',
        );
        $topology = [
            'topology' => 'split_web_api',
            'frontend_type' => 'vite-spa',
            'backend' => [
                'root' => 'apps/api',
                'port' => 8000,
                'working_directory' => '/app/apps/api',
                'start_command' => $runtime->command,
            ],
            'frontend' => [
                'root' => 'apps/mobile',
                'port' => 3000,
                'working_directory' => '/app/apps/mobile',
                'start_command' => ['sh', '-lc', 'exit 1'],
            ],
        ];
        $service = new ContainerDeploymentService;
        $method = new \ReflectionMethod($service, 'renderCompose');
        $yaml = $method->invoke(
            $service,
            $template,
            $deployment->container_name,
            30123,
            [],
            null,
            $deployment,
            '22-alpine',
            '/srv/service/app',
            $runtime,
            null,
            false,
            'frontend',
            8001,
            $topology,
        );
        $compose = Yaml::parse($yaml);

        $this->assertSame(['8000'], $compose['services']['backend']['expose']);
        $this->assertArrayNotHasKey('ports', $compose['services']['backend']);
        $this->assertSame(['30123:8080'], $compose['services']['edge']['ports']);
        $this->assertArrayNotHasKey('ports', $compose['services']['frontend']);
        $this->assertSame('nginx:1.27-alpine', $compose['services']['frontend']['image']);
        $this->assertContains(
            '/srv/service/app/apps/mobile/dist:/usr/share/nginx/html:ro',
            $compose['services']['frontend']['volumes'],
        );
        $this->assertSame('http://backend:8000', $compose['services']['backend']['environment']['INTERNAL_API_URL']);
    }

    #[Test]
    public function compose_runs_expo_web_as_a_node_sidecar_on_the_same_project(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'nodejs',
            'docker_image' => 'node:22-alpine',
            'default_port' => 3000,
            'required_cpu_cores' => 1,
            'required_ram_mb' => 1024,
            'volume_paths' => ['app_data' => '/app'],
        ]);
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-2-nodejs',
            'assigned_port' => 30123,
            'restart_policy' => 'always',
            'cpu_limit' => 1,
            'memory_limit_mb' => 1024,
            'selected_version' => '22-alpine',
        ]);
        $runtime = new ApplicationRuntime(
            ['sh', '-lc', 'cd /app/apps/api && exec npm start'],
            'package-script',
            'npm start',
            '/app/apps/api',
        );
        $frontendCommand = ['sh', '-lc', 'npx --yes serve@14 dist -s --listen tcp://0.0.0.0:${PORT:-3000}'];
        $topology = [
            'topology' => 'split_web_api',
            'frontend_type' => 'expo-web',
            'backend' => [
                'root' => 'apps/api',
                'port' => 8000,
                'working_directory' => '/app/apps/api',
                'start_command' => $runtime->command,
            ],
            'frontend' => [
                'root' => 'apps/mobile',
                'port' => 3000,
                'working_directory' => '/app/apps/mobile',
                'start_command' => $frontendCommand,
            ],
        ];
        $service = new ContainerDeploymentService;
        $method = new \ReflectionMethod($service, 'renderCompose');
        $yaml = $method->invoke(
            $service,
            $template,
            $deployment->container_name,
            30123,
            [],
            null,
            $deployment,
            '22-alpine',
            '/srv/service/app',
            $runtime,
            null,
            false,
            'frontend',
            8001,
            $topology,
        );
        $compose = Yaml::parse($yaml);

        $this->assertSame('node:22-alpine', $compose['services']['frontend']['image']);
        $this->assertSame('/app/apps/mobile', $compose['services']['frontend']['working_dir']);
        $this->assertSame($frontendCommand, $compose['services']['frontend']['command']);
        $this->assertSame('/api', $compose['services']['frontend']['environment']['EXPO_PUBLIC_API_URL']);
        $this->assertSame(['30123:8080'], $compose['services']['edge']['ports']);
        $this->assertArrayNotHasKey('ports', $compose['services']['frontend']);
        $this->assertArrayNotHasKey('ports', $compose['services']['backend']);
    }

    #[Test]
    public function manual_node_pin_must_satisfy_backend_and_frontend_engines(): void
    {
        $template = new ContainerTemplate(['slug' => 'nodejs']);
        $service = new Service([
            'service_meta' => ['node_version_source' => 'manual'],
        ]);
        $product = new Product;
        $product->setRelation('containerTemplate', $template);
        $service->setRelation('product', $product);
        $deployment = new ContainerDeployment(['selected_version' => '20-alpine']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('does not satisfy both workloads');
        (new ContainerDeploymentService)->resolveSplitNodeVersion(
            $service,
            $deployment,
            $template,
            [
                'topology' => 'split_web_api',
                'backend' => ['node_engine' => '>=18'],
                'frontend' => ['node_engine' => '>=22'],
            ],
            '20-alpine',
        );
    }

    #[Test]
    public function deployment_identifies_a_persisted_split_node_stack(): void
    {
        $service = new Service([
            'service_meta' => ['node_workloads' => ['topology' => 'split_web_api']],
        ]);
        $deployment = new ContainerDeployment([
            'docker_compose_content' => "services:\n  backend:\n    image: node\n  frontend:\n    image: node\n  edge:\n    image: node\n",
        ]);
        $deployment->setRelation('service', $service);

        $this->assertTrue((new ContainerDeploymentService)->usesNodeWebSidecarStack($deployment));
    }

    #[Test]
    public function split_backend_runtime_detection_uses_include_node_bootstrap(): void
    {
        $package = json_encode([
            'scripts' => ['start' => 'node server.js'],
            'dependencies' => ['express' => '^5.0'],
        ], JSON_THROW_ON_ERROR);
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(function (string $command) use ($package): string {
            if (str_contains($command, '/srv/app/apps/api/package.json') && str_contains($command, 'head -c')) {
                return $package;
            }
            if (str_contains($command, '[ -f ') && str_contains($command, '/srv/app/apps/api/package.json')) {
                return 'yes';
            }

            return 'no';
        });

        $template = new ContainerTemplate(['slug' => 'nodejs', 'default_port' => 3000]);
        $method = new \ReflectionMethod(ContainerDeploymentService::class, 'resolveApplicationRuntime');
        $runtime = $method->invoke(
            new ContainerDeploymentService,
            $ssh,
            $template,
            '/srv/app',
            [
                'topology' => 'split_web_api',
                'backend' => ['root' => 'apps/api'],
            ],
        );

        $this->assertInstanceOf(ApplicationRuntime::class, $runtime);
        $this->assertSame('/app/apps/api', $runtime->containerWorkdir);
        $this->assertStringContainsString('exec npm start', $runtime->command[2]);
        $this->assertStringNotContainsString('npm install', $runtime->command[2]);
    }
}
