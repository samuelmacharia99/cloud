<?php

namespace Tests\Unit\Provisioning;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerAutoDeployService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class ContainerAutoDeployServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_enable_requires_connected_repository(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['type' => 'container_hosting']);
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'service_meta' => [],
        ]);

        $git = Mockery::mock(ContainerGitRepositoryService::class);
        $git->shouldReceive('supportsTemplate')->andReturn(true);
        $git->shouldReceive('repositorySettings')->andReturn([
            'url' => '',
            'branch' => 'main',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Connect a Git repository');

        (new ContainerAutoDeployService($git))->enable($service);
    }

    public function test_secret_matches_and_webhook_ignores_other_branch(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['type' => 'container_hosting']);
        $plain = 'test-webhook-secret-token-value-123456';
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'service_meta' => [
                'auto_deploy_enabled' => true,
                'auto_deploy_secret_hash' => Hash::make($plain),
            ],
        ]);

        $git = Mockery::mock(ContainerGitRepositoryService::class);
        $git->shouldReceive('supportsTemplate')->andReturn(true);
        $git->shouldReceive('repositorySettings')->andReturn([
            'url' => 'https://github.com/acme/app.git',
            'branch' => 'main',
        ]);

        $auto = new ContainerAutoDeployService($git);
        $this->assertTrue($auto->secretMatches($service, $plain));
        $this->assertFalse($auto->secretMatches($service, 'wrong'));

        $request = Request::create('/webhook', 'POST', ['ref' => 'refs/heads/develop'], [], [], [
            'HTTP_X_TALKSASA_TOKEN' => $plain,
        ]);

        $result = $auto->handleWebhook($service, $request);
        $this->assertFalse($result['queued']);
        $this->assertStringContainsString('Ignored', $result['message']);
    }
}
