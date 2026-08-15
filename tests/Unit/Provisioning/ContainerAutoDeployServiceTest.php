<?php

namespace Tests\Unit\Provisioning;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerAutoDeployService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        $git->shouldReceive('supportsService')->andReturn(true);
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
        $git->shouldReceive('supportsService')->andReturn(true);
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

    public function test_standard_github_signature_is_accepted_and_non_push_events_are_ignored(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['type' => 'container_hosting']);
        $plain = 'github-webhook-secret-value-123456789';
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'service_meta' => [
                'auto_deploy_enabled' => true,
                'auto_deploy_secret_hash' => Hash::make($plain),
                ContainerAutoDeployService::SECRET_ENCRYPTED_META_KEY => Crypt::encryptString($plain),
            ],
        ]);

        $git = Mockery::mock(ContainerGitRepositoryService::class);
        $body = json_encode(['zen' => 'Keep it logically awesome'], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, $plain);
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);

        $result = (new ContainerAutoDeployService($git))->handleWebhook($service, $request);

        $this->assertFalse($result['queued']);
        $this->assertStringContainsString('not a branch push', $result['message']);
    }

    public function test_missing_branch_ref_does_not_trigger_a_deploy(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['type' => 'container_hosting']);
        $plain = 'custom-webhook-secret-value-123456';
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'service_meta' => [
                'auto_deploy_enabled' => true,
                'auto_deploy_secret_hash' => Hash::make($plain),
            ],
        ]);

        $git = Mockery::mock(ContainerGitRepositoryService::class);
        $git->shouldReceive('supportsService')->once()->andReturn(true);
        $git->shouldReceive('repositorySettings')->once()->andReturn([
            'url' => 'https://github.com/acme/app.git',
            'branch' => 'main',
        ]);

        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_TALKSASA_TOKEN' => $plain,
        ]);
        $result = (new ContainerAutoDeployService($git))->handleWebhook($service, $request);

        $this->assertFalse($result['queued']);
        $this->assertStringContainsString('not for the connected branch', $result['message']);
    }
}
