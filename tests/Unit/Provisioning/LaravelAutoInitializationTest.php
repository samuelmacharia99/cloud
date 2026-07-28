<?php

namespace Tests\Unit\Provisioning;

use App\Jobs\InitializeContainerAppJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\LaravelAppInitializationService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaravelAutoInitializationTest extends TestCase
{
    use RefreshDatabase;

    private function laravelService(array $meta = []): array
    {
        $user = User::factory()->customer()->create();
        $template = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel app',
                'category' => 'web',
                'docker_image' => 'talksasa/laravel-runtime:8.3',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 3,
                'is_active' => true,
                'order' => 1,
            ]
        );
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);
        $node = Node::factory()->create(['type' => 'container_host']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'service_meta' => $meta,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-1-service-1-laravel',
            'status' => 'running',
        ]);

        return [$service->fresh(['product.containerTemplate', 'user']), $deployment->fresh()];
    }

    #[Test]
    public function it_queues_initialization_for_empty_laravel_app(): void
    {
        Bus::fake();
        [$service, $deployment] = $this->laravelService();

        $ssh = Mockery::mock(SSHService::class);
        $directories = Mockery::mock(ContainerAppDirectoryService::class);
        $directories->shouldReceive('hasLaravelProject')->once()->andReturn(false);

        $this->app->instance(ContainerAppDirectoryService::class, $directories);

        $result = app(LaravelAppInitializationService::class)->queueFreshInstallationIfNeeded(
            $service,
            $deployment,
            $ssh,
        );

        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('initialization_id', $result);

        Bus::assertDispatched(InitializeContainerAppJob::class, function (InitializeContainerAppJob $job) use ($result) {
            return $job->initializationId === $result['initialization_id'];
        });

        $service->refresh();
        $this->assertNotEmpty($service->service_meta['laravel_auto_init_queued_at'] ?? null);
    }

    #[Test]
    public function it_skips_when_git_repository_is_configured(): void
    {
        Bus::fake();
        [$service, $deployment] = $this->laravelService([
            'source_repo_url' => 'https://github.com/example/app.git',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('exec');

        $result = app(LaravelAppInitializationService::class)->queueFreshInstallationIfNeeded(
            $service,
            $deployment,
            $ssh,
        );

        $this->assertTrue($result['skipped']);
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_skips_when_laravel_already_present(): void
    {
        Bus::fake();
        [$service, $deployment] = $this->laravelService();

        $ssh = Mockery::mock(SSHService::class);
        $directories = Mockery::mock(ContainerAppDirectoryService::class);
        $directories->shouldReceive('hasLaravelProject')->once()->andReturn(true);
        $this->app->instance(ContainerAppDirectoryService::class, $directories);

        $result = app(LaravelAppInitializationService::class)->queueFreshInstallationIfNeeded(
            $service,
            $deployment,
            $ssh,
        );

        $this->assertTrue($result['skipped']);
        Bus::assertNothingDispatched();
    }
}
