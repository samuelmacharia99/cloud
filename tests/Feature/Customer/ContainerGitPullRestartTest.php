<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Jobs\PullContainerGitRepositoryJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerGitPull;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContainerGitPullRestartTest extends TestCase
{
    use RefreshDatabase;

    private function makeNodeService(User $user): Service
    {
        $node = Node::factory()->create(['is_active' => true]);
        $template = ContainerTemplate::factory()->create(['slug' => 'nodejs']);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'source_repo_url' => 'https://github.com/acme/app.git',
                'source_repo_branch' => 'main',
            ],
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        return $service->fresh(['product.containerTemplate', 'containerDeployment']);
    }

    public function test_customer_can_cancel_an_active_git_pull(): void
    {
        $user = User::factory()->create();
        $service = $this->makeNodeService($user);

        $pull = ContainerGitPull::create([
            'service_id' => $service->id,
            'container_deployment_id' => $service->containerDeployment->id,
            'user_id' => $user->id,
            'template_slug' => 'nodejs',
            'status' => ContainerGitPull::STATUS_RUNNING,
            'options' => ['force_rebuild' => false],
            'steps' => [
                ['key' => 'validate', 'label' => 'Validate', 'status' => 'completed'],
                ['key' => 'post_pull', 'label' => 'Post-pull', 'status' => 'running'],
            ],
            'started_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson(
            route('customer.services.container.git-repository.cancel', $service)
        );

        $response->assertOk()
            ->assertJsonPath('pull.status', ContainerGitPull::STATUS_CANCELLED);

        $this->assertDatabaseHas('container_git_pulls', [
            'id' => $pull->id,
            'status' => ContainerGitPull::STATUS_CANCELLED,
        ]);
    }

    public function test_customer_can_restart_a_failed_git_pull(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $service = $this->makeNodeService($user);

        ContainerGitPull::create([
            'service_id' => $service->id,
            'container_deployment_id' => $service->containerDeployment->id,
            'user_id' => $user->id,
            'template_slug' => 'nodejs',
            'status' => ContainerGitPull::STATUS_FAILED,
            'options' => [
                'replace_existing' => false,
                'run_composer' => true,
                'run_migrations' => true,
                'force_rebuild' => true,
            ],
            'steps' => [
                ['key' => 'post_pull', 'label' => 'Post-pull', 'status' => 'failed'],
            ],
            'error_message' => 'Node post-pull step failed',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson(
            route('customer.services.container.git-repository.restart', $service),
            ['force_rebuild' => true]
        );

        $response->assertOk()
            ->assertJsonPath('pull.status', ContainerGitPull::STATUS_PENDING);

        Queue::assertPushed(PullContainerGitRepositoryJob::class);
        $this->assertDatabaseHas('container_git_pulls', [
            'service_id' => $service->id,
            'status' => ContainerGitPull::STATUS_PENDING,
        ]);
    }

    public function test_restart_cancels_in_progress_pull_before_starting_again(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $service = $this->makeNodeService($user);

        $stuck = ContainerGitPull::create([
            'service_id' => $service->id,
            'container_deployment_id' => $service->containerDeployment->id,
            'user_id' => $user->id,
            'template_slug' => 'nodejs',
            'status' => ContainerGitPull::STATUS_RUNNING,
            'options' => ['force_rebuild' => false],
            'steps' => [
                ['key' => 'sync', 'label' => 'Sync', 'status' => 'running'],
            ],
            'started_at' => now()->subMinutes(20),
        ]);

        $response = $this->actingAs($user)->postJson(
            route('customer.services.container.git-repository.restart', $service)
        );

        $response->assertOk();

        $this->assertDatabaseHas('container_git_pulls', [
            'id' => $stuck->id,
            'status' => ContainerGitPull::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('container_git_pulls', [
            'service_id' => $service->id,
            'status' => ContainerGitPull::STATUS_PENDING,
        ]);
        Queue::assertPushed(PullContainerGitRepositoryJob::class);
    }
}
