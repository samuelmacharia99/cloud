<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ApplicationRuntime;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerStackCommandService;
use App\Services\Provisioning\ContainerTemplateEnvironmentService;
use App\Services\Provisioning\RuntimeImageProvisioner;
use App\Services\SSH\SSHService;
use Illuminate\Container\Container;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ContainerDeploymentComposeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These are container-free unit tests, but the deployment service logs recovery
        // steps, so give the Log facade somewhere harmless to write.
        $container = new Container;
        $container->instance('log', new Logger(new Monolog('testing', [new NullHandler])));
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[Test]
    public function wordpress_template_does_not_auto_resolve_injected_database_sidecar(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'wordpress',
            'compose_services' => [
                'mysql' => ['image' => 'mysql:8.0'],
            ],
        ]);

        $service = new Service;
        $service->id = 10;
        $service->service_meta = [];

        $deployer = new ContainerDeploymentService(
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'resolveDatabaseTemplate');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($deployer, $service, $template));
    }

    #[Test]
    public function product_package_limits_override_template_defaults_for_display(): void
    {
        $template = new ContainerTemplate([
            'required_cpu_cores' => 0.5,
            'required_ram_mb' => 256,
            'required_storage_gb' => 2,
        ]);

        $product = new Product([
            'type' => 'container_hosting',
            'resource_limits' => [
                'cpu' => 1,
                'memory' => 1000,
                'disk' => 10,
            ],
        ]);

        $limits = $product->getIncludedContainerLimits($template);

        $this->assertSame(1.0, $limits['cpu']);
        $this->assertSame(1000, $limits['memory_mb']);
        $this->assertSame(10.0, $limits['disk_gb']);
    }

    #[Test]
    public function render_compose_skips_injected_db_when_template_already_has_mysql(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'wordpress',
            'docker_image' => 'wordpress:latest',
            'default_port' => 80,
            'required_cpu_cores' => 1,
            'required_ram_mb' => 512,
            'compose_services' => [
                'mysql' => [
                    'image' => 'mysql:8.0',
                    'environment' => [],
                    'volumes' => [
                        'mysql_data:/var/lib/mysql',
                    ],
                ],
            ],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-1-service-10',
            31010,
            [
                'WORDPRESS_DB_NAME' => 'wordpress',
                'WORDPRESS_DB_USER' => 'wordpress',
                'WORDPRESS_DB_PASSWORD' => 'generated-password',
                'MYSQL_ROOT_PASSWORD' => 'root-password',
            ],
            null,
            null,
            null,
            null,
            null
        );

        $this->assertStringContainsString('mysql:8.0', $yaml);
        $this->assertStringNotContainsString("\n  db:\n", $yaml);
        $this->assertStringContainsString('generated-password', $yaml);
        $this->assertStringContainsString('depends_on', $yaml);
        $this->assertStringContainsString('service_started', $yaml);
        $this->assertStringNotContainsString('mem_limit', $yaml);
        $this->assertStringContainsString('mem_reservation', $yaml);
        $this->assertStringContainsString('elastic-v1', $yaml);
        $this->assertStringContainsString('innodb-buffer-pool-size', $yaml);
        $this->assertStringContainsString('mysql_data:/var/lib/mysql', $yaml);
        $this->assertStringContainsString('uploads.ini', $yaml);
        $this->assertStringContainsString('/usr/local/etc/php/conf.d/uploads.ini', $yaml);
        $this->assertMatchesRegularExpression('/volumes:\s*\n(?:.*\n)*?\s+mysql_data:/', $yaml);
    }

    #[Test]
    public function render_compose_bind_mounts_wordpress_host_app_to_var_www_html(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'wordpress',
            'docker_image' => 'wordpress:latest',
            'default_port' => 80,
            'required_cpu_cores' => 1,
            'required_ram_mb' => 512,
            'volume_paths' => [
                'wp_data' => '/var/www/html',
                'wp_content' => '/var/www/html/wp-content',
            ],
            'compose_services' => [
                'mysql' => [
                    'image' => 'mysql:8.0',
                    'environment' => [],
                    'volumes' => [
                        'mysql_data:/var/lib/mysql',
                    ],
                ],
            ],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $hostApp = '/opt/talksasa/containers/user-76-service-97-wordpress/app';
        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-76-service-97-wordpress',
            30004,
            [
                'WORDPRESS_DB_NAME' => 'wordpress',
                'WORDPRESS_DB_USER' => 'wordpress',
                'WORDPRESS_DB_PASSWORD' => 'generated-password',
                'MYSQL_ROOT_PASSWORD' => 'root-password',
            ],
            null,
            null,
            null,
            $hostApp,
            null
        );

        $this->assertStringContainsString("{$hostApp}:/var/www/html", $yaml);
        $this->assertStringNotContainsString('wp_data:/var/www/html', $yaml);
        $this->assertStringNotContainsString('wp_content:/var/www/html/wp-content', $yaml);
    }

    #[Test]
    public function render_compose_skips_nested_wp_content_volume_declared_before_wp_data(): void
    {
        // Seeded template order: wp_content is listed first, and Docker mounts the deeper
        // path last, so an order-sensitive guard leaves uploads inside a hidden volume.
        $template = new ContainerTemplate([
            'slug' => 'wordpress',
            'docker_image' => 'wordpress:latest',
            'default_port' => 80,
            'required_cpu_cores' => 1,
            'required_ram_mb' => 512,
            'volume_paths' => [
                'wp_content' => '/var/www/html/wp-content',
                'wp_data' => '/var/www/html',
            ],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $hostApp = '/opt/talksasa/containers/user-1-service-20-wordpress/app';
        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-1-service-20-wordpress',
            30020,
            [],
            null,
            null,
            null,
            $hostApp,
            null
        );

        $this->assertStringContainsString("{$hostApp}:/var/www/html", $yaml);
        $this->assertStringNotContainsString('wp_content:/var/www/html/wp-content', $yaml);
    }

    #[Test]
    public function shadowed_wp_content_volume_is_copied_onto_the_bind_mount(): void
    {
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-20-wordpress',
            'docker_compose_content' => "services:\n  app:\n    volumes:\n"
                ."      - /opt/app:/var/www/html\n      - wp_content:/var/www/html/wp-content\n",
        ]);
        $deployment->id = 55;

        $commands = [];
        $ssh = $this->createMock(SSHService::class);
        $ssh->method('exec')->willReturnCallback(function (string $command) use (&$commands) {
            $commands[] = $command;

            if (str_contains($command, 'docker volume ls')) {
                return "other_data\nuser-1-service-20-wordpress_wp_content\n";
            }

            if (str_contains($command, 'docker volume inspect')) {
                return "/var/lib/docker/volumes/user-1-service-20-wordpress_wp_content/_data\n";
            }

            return '';
        });

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'rescueShadowedWordPressContent');
        $method->setAccessible(true);
        $method->invoke(new ContainerDeploymentService(templateEnvironment: new ContainerTemplateEnvironmentService), $ssh, $deployment);

        $copy = collect($commands)->first(fn (string $command) => str_contains($command, 'cp -an'));

        $this->assertNotNull($copy);
        $this->assertStringContainsString('/var/lib/docker/volumes/user-1-service-20-wordpress_wp_content/_data/.', $copy);
        $this->assertStringContainsString('user-1-service-20-wordpress/app/wp-content', $copy);
    }

    #[Test]
    public function stacks_without_a_shadowed_volume_are_left_alone(): void
    {
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-20-wordpress',
            'docker_compose_content' => "services:\n  app:\n    volumes:\n      - /opt/app:/var/www/html\n",
        ]);

        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->never())->method('exec');

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'rescueShadowedWordPressContent');
        $method->setAccessible(true);
        $method->invoke(new ContainerDeploymentService(templateEnvironment: new ContainerTemplateEnvironmentService), $ssh, $deployment);
    }

    #[Test]
    public function render_compose_adds_autostart_command_for_nodejs(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'nodejs',
            'docker_image' => 'node:20-alpine',
            'default_port' => 3000,
            'required_cpu_cores' => 0.5,
            'required_ram_mb' => 256,
            'volume_paths' => ['app_data' => '/app'],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $runtime = new ApplicationRuntime(
            ['sh', '-lc', 'cd /app && export PORT=${PORT:-3000} && exec npm start'],
            'package.json',
            'npm start'
        );

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-1-service-11-nodejs',
            31011,
            ['PORT' => '3000', 'NODE_ENV' => 'production'],
            null,
            null,
            null,
            '/opt/talksasa/containers/user-1-service-11-nodejs/app',
            $runtime
        );

        $this->assertStringContainsString('working_dir: /app', $yaml);
        $this->assertStringContainsString('npm start', $yaml);
    }

    #[Test]
    public function render_compose_allows_bound_domains_on_the_vite_preview_server(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'nodejs',
            'docker_image' => 'node:20-bookworm-slim',
            'default_port' => 3000,
            'required_cpu_cores' => 0.5,
            'required_ram_mb' => 512,
            'volume_paths' => ['app_data' => '/app'],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $runtime = new ApplicationRuntime(
            ['sh', '-lc', 'cd /app && exec npx vite preview --host 0.0.0.0 --port ${PORT:-3000} --strictPort'],
            'vite',
            'Vite production preview'
        );

        $deployment = new ContainerDeployment(['container_name' => 'user-462-service-193-nodejs']);
        $deployment->setRelation('domains', collect([
            new ContainerDomain(['domain' => 'gateway.errandly.site', 'status' => 'active']),
        ]));
        $deployment->setRelation('node', new Node(['hostname' => 'lani.talksasa.com']));

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-462-service-193-nodejs',
            30022,
            ['NODE_ENV' => 'production'],
            null,
            $deployment,
            null,
            '/opt/talksasa/containers/user-462-service-193-nodejs/app',
            $runtime
        );

        $this->assertStringContainsString(ContainerDeploymentService::VITE_ALLOWED_HOSTS_ENV, $yaml);
        $this->assertStringContainsString('gateway.errandly.site', $yaml);
        $this->assertStringContainsString('.gateway.errandly.site', $yaml);
        $this->assertStringContainsString('lani.talksasa.com', $yaml);
    }

    #[Test]
    public function render_compose_uses_custom_laravel_document_root(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'laravel',
            'docker_image' => 'talksasa/laravel-runtime:8.3',
            'default_port' => 8000,
            'required_cpu_cores' => 0.5,
            'required_ram_mb' => 512,
            'volume_paths' => ['app_data' => '/app'],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(true);
        $runtimeImages->method('resolveImageReference')->willReturn(['image' => 'talksasa/laravel-runtime:8.3']);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-1-service-12-laravel',
            31012,
            ['APP_ENV' => 'production'],
            null,
            null,
            null,
            '/opt/talksasa/containers/user-1-service-12-laravel/app',
            null,
            '/app'
        );

        $this->assertStringContainsString('talksasa-php-server', $yaml);
        $this->assertStringContainsString('pull_policy: never', $yaml);
        $this->assertStringNotContainsString("- '-S'\n", $yaml);
    }

    #[Test]
    public function render_compose_builds_laravel_next_sidecar_stack(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'laravel',
            'docker_image' => 'talksasa/laravel-runtime:8.3',
            'default_port' => 8000,
            'required_cpu_cores' => 1.0,
            'required_ram_mb' => 1024,
            'volume_paths' => ['app_data' => '/app'],
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(true);
        $runtimeImages->method('resolveImageReference')->willReturn(['image' => 'talksasa/laravel-runtime:8.3']);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $hostApp = '/opt/talksasa/containers/user-1-service-12-laravel/app';
        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-1-service-12-laravel',
            31012,
            [
                'APP_ENV' => 'production',
                'FRONTEND_URL' => 'https://atlas.example.com',
                'APP_URL' => 'https://atlas.example.com',
            ],
            null,
            null,
            null,
            $hostApp,
            null,
            '/app/backend/public',
            true,
            'frontend',
            8000,
        );

        $this->assertStringContainsString("\n  backend:\n", $yaml);
        $this->assertStringContainsString("\n  frontend:\n", $yaml);
        $this->assertStringContainsString("\n  edge:\n", $yaml);
        $this->assertStringContainsString('container_name: user-1-service-12-laravel', $yaml);
        $this->assertStringContainsString('user-1-service-12-laravel-frontend', $yaml);
        $this->assertStringContainsString('user-1-service-12-laravel-edge', $yaml);
        $this->assertStringContainsString('31012:8080', $yaml);
        $this->assertStringContainsString('http://backend:8000', $yaml);
        $this->assertStringContainsString('https://atlas.example.com', $yaml);
        $this->assertStringContainsString('NEXT_PUBLIC_APP_URL', $yaml);
        $this->assertStringContainsString('BACKEND_HOST: backend', $yaml);
        $this->assertStringContainsString('FRONTEND_HOST: frontend', $yaml);
        // Edge must not wait on frontend so /api can come up during Next builds.
        $this->assertDoesNotMatchRegularExpression(
            '/edge:[\s\S]*?depends_on:\s*\n(?:\s*-\s*backend\s*\n)?\s*-\s*frontend/m',
            $yaml
        );
        $this->assertStringContainsString($hostApp.':/app', $yaml);
        $this->assertStringContainsString("ports:\n      - '31012:8080'", $yaml);
        $this->assertStringContainsString('$$BACKEND_DIR', $yaml);
        $this->assertStringNotContainsString('The "BACKEND_DIR" variable', $yaml);
        // Public port belongs to edge only (backend uses expose, not host ports).
        $this->assertMatchesRegularExpression('/backend:[\s\S]*?expose:\s*\n\s*-\s*[\'"]?8000/', $yaml);
    }

    #[Test]
    public function project_recipe_skips_laravel_next_sidecar_install(): void
    {
        $stackCommands = $this->createMock(ContainerStackCommandService::class);
        $stackCommands->expects($this->never())->method('installLaravelFrontendDependencies');
        $stackCommands->expects($this->never())->method('hostHasNextFrontend');

        $appDirectory = $this->createMock(ContainerAppDirectoryService::class);
        $appDirectory->expects($this->never())->method('hostAppPath');

        $deployer = new ContainerDeploymentService(
            appDirectory: $appDirectory,
            templateEnvironment: new ContainerTemplateEnvironmentService,
            stackCommands: $stackCommands,
        );

        $service = new Service;
        $service->id = 42;
        $service->service_meta = [
            'project_recipe' => 'laravel_next',
            'project_role' => 'backend',
            'project_billing_anchor' => true,
            'frontend' => 'nextjs',
        ];

        $deployment = new ContainerDeployment;
        $deployment->id = 7;
        $deployment->container_name = 'user-1-service-42-api';

        $ssh = $this->createMock(SSHService::class);

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'installLaravelFrontendAfterDeploy');
        $method->setAccessible(true);
        $method->invoke($deployer, $ssh, $service, $deployment);
    }

    #[Test]
    public function render_compose_joins_the_shared_host_network_instead_of_a_new_subnet(): void
    {
        $template = new ContainerTemplate([
            'slug' => 'static-site',
            'docker_image' => 'nginx:alpine',
            'default_port' => 80,
            'required_cpu_cores' => 0.25,
            'required_ram_mb' => 128,
        ]);

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);
        $yaml = $method->invoke(
            $deployer,
            $template,
            'user-67-service-253-static-site',
            30100,
            [],
            null,
            null,
            null,
            null,
            null
        );

        $this->assertStringContainsString('talksasa-net', $yaml);
        $this->assertStringContainsString('external: true', $yaml);
        $this->assertStringNotContainsString('talksasa-user-67-service-253-static-site', $yaml);
        $this->assertTrue($deployer->isDockerAddressPoolExhausted(
            'failed to create network talksasa-user-67-service-253-static-site: Error response from daemon: all predefined address pools have been fully subnetted'
        ));
    }

    #[Test]
    public function render_compose_sets_official_agent_gateway_commands_and_volumes(): void
    {
        $this->assertSame(['gateway', 'run'], ContainerDeploymentService::imageGatewayCommand('hermes'));
        $this->assertSame(
            ['node', 'dist/index.js', 'gateway', '--bind', 'lan', '--port', '18789'],
            ContainerDeploymentService::imageGatewayCommand('openclaw')
        );
        $this->assertNull(ContainerDeploymentService::imageGatewayCommand('ghost'));

        $runtimeImages = $this->createMock(RuntimeImageProvisioner::class);
        $runtimeImages->method('usesRuntimeImage')->willReturn(false);

        $deployer = new ContainerDeploymentService(
            runtimeImages: $runtimeImages,
            templateEnvironment: new ContainerTemplateEnvironmentService
        );

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'renderCompose');
        $method->setAccessible(true);

        $hermesYaml = $method->invoke(
            $deployer,
            new ContainerTemplate([
                'slug' => 'hermes',
                'docker_image' => 'nousresearch/hermes-agent:latest',
                'default_port' => 9119,
                'required_cpu_cores' => 1,
                'required_ram_mb' => 2048,
                'volume_paths' => ['hermes_data' => '/opt/data'],
            ]),
            'user-1-service-10-hermes',
            31010,
            [],
            null,
            null,
            null,
            null,
            null
        );

        $this->assertStringContainsString('nousresearch/hermes-agent:latest', $hermesYaml);
        $this->assertStringContainsString("command:\n      - gateway\n      - run", $hermesYaml);
        $this->assertStringContainsString('hermes_data:/opt/data', $hermesYaml);
        $this->assertStringContainsString("ports:\n      - '31010:9119'", $hermesYaml);

        $openClawYaml = $method->invoke(
            $deployer,
            new ContainerTemplate([
                'slug' => 'openclaw',
                'docker_image' => 'openclaw/openclaw:latest',
                'default_port' => 18789,
                'required_cpu_cores' => 1,
                'required_ram_mb' => 2048,
                'volume_paths' => [
                    'openclaw_state' => '/home/node/.openclaw',
                    'openclaw_workspace' => '/home/node/.openclaw/workspace',
                ],
            ]),
            'user-1-service-11-openclaw',
            31011,
            [],
            null,
            null,
            null,
            null,
            null
        );

        $this->assertStringContainsString('openclaw/openclaw:latest', $openClawYaml);
        $this->assertStringContainsString('--bind', $openClawYaml);
        $this->assertStringContainsString('lan', $openClawYaml);
        $this->assertStringContainsString('openclaw_state:/home/node/.openclaw', $openClawYaml);
        $this->assertStringContainsString("ports:\n      - '31011:18789'", $openClawYaml);
    }
}
