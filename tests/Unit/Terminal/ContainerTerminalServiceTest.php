<?php

namespace Tests\Unit\Terminal;

use App\Exceptions\SSH\SSHCommandException;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\ContainerTerminalSession;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Terminal\ContainerTerminalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ContainerTerminalServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_long_timeouts_for_artisan_migration_commands(): void
    {
        config([
            'terminal.command_timeouts.artisan_long' => 900,
            'terminal.command_timeouts.artisan' => 600,
            'terminal.command_timeouts.default' => 120,
        ]);

        $service = new ContainerTerminalService;
        $method = new ReflectionMethod(ContainerTerminalService::class, 'commandTimeoutSeconds');
        $method->setAccessible(true);

        $this->assertSame(
            900,
            $method->invoke($service, 'php artisan migrate:fresh --seed --force')
        );
        $this->assertSame(
            600,
            $method->invoke($service, 'php artisan config:cache')
        );
        $this->assertSame(
            120,
            $method->invoke($service, 'ls -la')
        );
    }

    #[Test]
    public function it_formats_partial_output_when_ssh_command_fails(): void
    {
        $service = new ContainerTerminalService;
        $method = new ReflectionMethod(ContainerTerminalService::class, 'formatFailedCommandOutput');
        $method->setAccessible(true);

        $formatted = $method->invoke($service, new SSHCommandException(
            'docker exec test',
            "Running migrations.\n  2024_01_01_create_tables",
            'Command exited with status 124'
        ));

        $this->assertStringContainsString('Running migrations.', $formatted);
        $this->assertStringContainsString('Partial output is shown below', $formatted);
        $this->assertStringContainsString('Command exited with status 124', $formatted);
    }

    #[Test]
    public function it_adds_force_and_no_interaction_for_production_artisan_commands(): void
    {
        $this->assertSame(
            'php artisan migrate --no-interaction --force',
            ContainerTerminalService::applyArtisanProductionFlags('php artisan migrate')
        );
        $this->assertSame(
            'php artisan migrate:fresh --seed --no-interaction --force',
            ContainerTerminalService::applyArtisanProductionFlags('php artisan migrate:fresh --seed')
        );
        $this->assertSame(
            'php artisan db:seed --no-interaction --force',
            ContainerTerminalService::applyArtisanProductionFlags('php artisan db:seed')
        );
        $this->assertSame(
            'php artisan cache:clear --no-interaction',
            ContainerTerminalService::applyArtisanProductionFlags('php artisan cache:clear')
        );
        $this->assertSame(
            'php artisan migrate --force --no-interaction',
            ContainerTerminalService::applyArtisanProductionFlags('php artisan migrate --force')
        );
        $this->assertSame(
            'ls -la',
            ContainerTerminalService::applyArtisanProductionFlags('ls -la')
        );
    }

    #[Test]
    public function it_uses_wordpress_html_root_for_docker_exec_workdir(): void
    {
        $user = User::factory()->customer()->create();
        $template = ContainerTemplate::create([
            'name' => 'WordPress',
            'slug' => 'wordpress',
            'docker_image' => 'wordpress:latest',
            'is_active' => true,
            'volume_paths' => [
                'wp_data' => '/var/www/html',
                'wp_content' => '/var/www/html/wp-content',
            ],
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
        ]);
        $deployment = ContainerDeployment::create([
            'service_id' => $service->id,
            'container_name' => 'user-246-service-22-wordpress',
            'status' => 'running',
        ]);
        $session = ContainerTerminalSession::create([
            'token' => bin2hex(random_bytes(16)),
            'service_id' => $service->id,
            'user_id' => $user->id,
            'deployment_id' => $deployment->id,
            'container_name' => $deployment->container_name,
            'cwd' => '/app', // legacy stuck value
            'status' => 'active',
            'ip_address' => '127.0.0.1',
            'expires_at' => now()->addHour(),
            'hard_expires_at' => now()->addDay(),
        ]);

        $terminal = new ContainerTerminalService;
        $this->assertSame('/var/www/html', $terminal->resolveAppRoot($session));

        $method = new ReflectionMethod(ContainerTerminalService::class, 'buildDockerExecCommand');
        $method->setAccessible(true);
        $cmd = $method->invoke($terminal, $session, 'ls -la');

        $this->assertStringContainsString("-w '/var/www/html'", $cmd);
        $this->assertStringNotContainsString('-w /app ', $cmd);
        $this->assertStringContainsString('/var/www/html', $cmd);
        $this->assertStringNotContainsString("cd '/app'", $cmd);
    }

    #[Test]
    public function it_keeps_app_root_for_nodejs_stacks(): void
    {
        $terminal = new ContainerTerminalService;
        $this->assertSame('/app', $terminal->resolveAppRootFromTemplate((object) [
            'slug' => 'nodejs',
            'volume_paths' => ['app_data' => '/app'],
        ]));
    }
}
