<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Product;
use App\Models\Service;
use App\Services\Provisioning\ApplicationRuntime;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\ContainerTemplateEnvironmentService;
use App\Services\Provisioning\RuntimeImageProvisioner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ContainerDeploymentComposeTest extends TestCase
{
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
        $this->assertStringContainsString('mysql_data:/var/lib/mysql', $yaml);
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

        $this->assertStringContainsString("- /app\n", $yaml);
        $this->assertStringContainsString('pull_policy: never', $yaml);
        $this->assertStringContainsString("- '-t'\n", $yaml);
    }
}
