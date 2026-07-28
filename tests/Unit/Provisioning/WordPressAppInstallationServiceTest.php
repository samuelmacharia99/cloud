<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\WordPressAdminLoginService;
use App\Services\Provisioning\WordPressAppInstallationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordPressAppInstallationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $slug): ContainerTemplate
    {
        return ContainerTemplate::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst($slug),
                'description' => 'Test '.$slug,
                'category' => 'web',
                'docker_image' => $slug === 'wordpress' ? 'wordpress:latest' : 'nginx:latest',
                'default_port' => 80,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 2,
                'is_active' => true,
                'order' => 1,
            ]
        );
    }

    #[Test]
    public function it_skips_non_wordpress_templates(): void
    {
        $user = User::factory()->customer()->create();
        $template = $this->template('laravel');
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'container_name' => 'user-1-service-1-laravel',
            'status' => 'running',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('exec');

        $result = app(WordPressAppInstallationService::class)->installIfNeeded(
            $service->fresh(['product.containerTemplate']),
            $deployment,
            $ssh,
            [],
        );

        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_runs_wp_core_install_when_not_yet_installed(): void
    {
        $user = User::factory()->customer()->create(['email' => 'owner@example.test']);
        $template = $this->template('wordpress');
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $node = Node::factory()->create([
            'type' => 'container_host',
            'ip_address' => '10.0.0.5',
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => 'My Blog',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-1-service-1-wordpress',
            'status' => 'running',
            'assigned_port' => 8080,
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(function (string $command) {
            if (str_contains($command, 'wp-includes/version.php')) {
                return "yes\n";
            }
            if (str_contains($command, 'mysqladmin ping')) {
                return "ready\n";
            }
            if (str_contains($command, 'command -v wp') || str_contains($command, 'wp --info')) {
                return "ok\n";
            }
            if (str_contains($command, 'wp core is-installed')) {
                static $checks = 0;
                $checks++;

                return $checks === 1 ? '' : "installed\n";
            }
            if (str_contains($command, 'wp core install')) {
                return "Success: WordPress installed successfully.\n";
            }
            if (str_contains($command, 'wp core version')) {
                return "6.7.1\n";
            }

            return '';
        });

        $adminLogin = Mockery::mock(WordPressAdminLoginService::class);
        $adminLogin->shouldReceive('resolvePublicBaseUrl')->andReturn('https://blog.example.test');

        $serviceInstaller = new WordPressAppInstallationService($adminLogin);
        $result = $serviceInstaller->installIfNeeded(
            $service->fresh(['product.containerTemplate', 'user', 'containerDeployment.node', 'containerDeployment.domains']),
            $deployment->fresh(['node', 'domains']),
            $ssh,
            [
                'WORDPRESS_ADMIN_USER' => 'admin',
                'WORDPRESS_ADMIN_PASSWORD' => 'secret-pass-123',
                'WORDPRESS_ADMIN_EMAIL' => 'owner@example.test',
                'MYSQL_ROOT_PASSWORD' => 'root-secret',
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['skipped']);
        $this->assertStringContainsString('6.7.1', $result['message']);

        $service->refresh();
        $this->assertNotEmpty($service->service_meta['wordpress_installed_at'] ?? null);
        $this->assertSame('https://blog.example.test', $service->service_meta['wordpress_install_url'] ?? null);
    }
}
