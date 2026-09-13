<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\CustomerProject;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Customer\StackFolder;
use App\Services\Customer\StackFolderBuilder;
use App\Services\Provisioning\ContainerStackMemberService;
use App\Services\Provisioning\StackMemberActionResult;
use App\Services\Provisioning\StackMemberResolver;
use App\Services\Provisioning\StackMemberState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A deployed stack is one folder on the project page: the application
 * containers and the database as rows, each with its observed state, and a
 * Restart that only the database carries.
 */
class CustomerProjectStackFoldersTest extends TestCase
{
    use RefreshDatabase;

    private const YAML = <<<'YAML'
services:
  backend:
    image: talksasa/laravel-runtime:8.3
    container_name: %1$s
  frontend:
    image: node:20
    container_name: %1$s-frontend
  edge:
    image: nginx:alpine
    container_name: %1$s-edge
  db:
    image: mysql:8
    container_name: %1$s-db
YAML;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_project_page_shows_one_folder_with_every_container_and_its_state(): void
    {
        Carbon::setTestNow('2026-09-13 10:10:00');
        [$customer, $project, $service, $deployment] = $this->deployedStack();
        $this->snapshot($deployment, '2026-09-13 10:08:00', [
            $deployment->container_name => 'running',
            $deployment->container_name.'-frontend' => 'running',
            $deployment->container_name.'-edge' => 'running',
            $deployment->container_name.'-db' => 'exited',
        ]);

        $response = $this->actingAs($customer)->get(route('customer.projects.show', $project));

        $response->assertOk()
            ->assertSee('data-stack-folder="stack-'.$service->id.'"', false)
            ->assertSee('Backend')
            ->assertSee('Frontend')
            ->assertSee('Edge')
            ->assertSee('Database')
            ->assertSee('4 containers')
            ->assertSee('1 of 4 not running')
            ->assertSee('checked 2 minutes ago')
            ->assertSee(route('customer.services.container.members.restart', [$service, 'db']), false)
            ->assertDontSee(route('customer.services.container.members.restart', [$service, 'backend']), false)
            ->assertSee(route('customer.services.container.restart', $service), false)
            ->assertSee(route('customer.projects.stacks.refresh', $project), false);

        // The database row is stopped, the app rows run; nothing offers Stop/Start for the database alone.
        $this->assertSame(1, substr_count($response->getContent(), 'data-container-state="stopped"'));
        $this->assertSame(3, substr_count($response->getContent(), 'data-container-state="running"'));
        $this->assertStringNotContainsString('members/db/stop', $response->getContent());
    }

    #[Test]
    public function a_stale_snapshot_renders_as_unknown_and_a_pending_stack_has_no_actions(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$customer, $project, $service, $deployment] = $this->deployedStack();
        $this->snapshot($deployment, '2026-09-13 09:00:00', [$deployment->container_name.'-db' => 'running']);

        $this->actingAs($customer)->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('data-container-state="unknown"', false)
            ->assertDontSee('data-container-state="running"', false);

        $pending = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $service->product_id,
            'project_id' => $project->id,
            'status' => 'pending',
            'name' => 'Not yet deployed',
            'service_meta' => ['frontend' => 'nextjs', 'backend' => 'laravel', 'database_id' => 'mysql'],
        ]);

        $response = $this->actingAs($customer)->get(route('customer.projects.show', $project))->assertOk();
        $this->assertStringContainsString('data-stack-folder="stack-'.$pending->id.'"', $response->getContent());
        $this->assertStringContainsString('Waiting for the first deployment', $response->getContent());
        $this->assertStringNotContainsString(route('customer.services.container.members.restart', [$pending, 'db']), $response->getContent());
    }

    #[Test]
    public function role_split_services_share_one_folder_and_staging_gets_its_own(): void
    {
        [$customer, $project, $api, $deployment] = $this->deployedStack();
        $web = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $api->product_id,
            'project_id' => $project->id,
            'status' => 'active',
            'name' => 'Web',
            'service_meta' => ['project_role' => 'frontend', 'project_role_label' => 'Web', 'project_recipe' => 'nodejs_web', 'backend_service_id' => $api->id, 'sibling_service_id' => $api->id],
        ]);
        $api->update(['service_meta' => ['project_role' => 'backend', 'project_role_label' => 'API', 'project_recipe' => 'nodejs_web', 'project_billing_anchor' => true, 'frontend_service_id' => $web->id, 'sibling_service_id' => $web->id]]);
        $staging = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $api->product_id,
            'project_id' => $project->id,
            'status' => 'active',
            'name' => 'Staging',
            'service_meta' => ['production_service_id' => $api->id],
        ]);

        $folders = app(StackFolderBuilder::class)->foldersFor(Service::query()->where('project_id', $project->id)->get());

        $this->assertCount(2, $folders);
        $this->assertSame('stack-'.$api->id, $folders[0]->key);
        $this->assertEqualsCanonicalizing([$api->id, $web->id], $folders[0]->services->pluck('id')->all());
        $this->assertTrue($folders[0]->isSplitAcrossServices());
        $this->assertSame('stack-'.$staging->id, $folders[1]->key);
        $this->assertSame(StackFolder::PENDING, $folders[1]->aggregateState, 'a stack without a deployment is pending');
    }

    #[Test]
    public function the_services_index_card_counts_containers_and_flags_a_stopped_one(): void
    {
        Carbon::setTestNow('2026-09-13 10:10:00');
        [$customer, $project, $service, $deployment] = $this->deployedStack();
        $this->snapshot($deployment, '2026-09-13 10:09:00', [
            $deployment->container_name => 'running',
            $deployment->container_name.'-frontend' => 'running',
            $deployment->container_name.'-edge' => 'running',
            $deployment->container_name.'-db' => 'exited',
        ]);

        $index = $this->actingAs($customer)->get(route('customer.services.index'));
        $index->assertOk()
            ->assertSee('4 containers')
            ->assertSee('1 not running', false);
    }

    #[Test]
    public function the_console_database_tab_shows_the_container_state_and_a_restart(): void
    {
        Carbon::setTestNow('2026-09-13 10:10:00');
        [$customer, $project, $service, $deployment] = $this->deployedStack();
        $deployment->update(['env_values' => ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db', 'DB_DATABASE' => 'app', 'DB_USERNAME' => 'app', 'DB_PASSWORD' => 'secret', 'MYSQL_ROOT_PASSWORD' => 'root']]);
        $this->snapshot($deployment, '2026-09-13 10:09:00', [$deployment->container_name.'-db' => 'running']);

        $this->actingAs($customer)->get(route('customer.services.container.show', $service))
            ->assertOk()
            ->assertSee('Database container')
            ->assertSee(route('customer.services.container.members.restart', [$service, 'db']), false)
            ->assertSee('Restart database');
    }

    #[Test]
    public function restarting_the_database_from_the_page_calls_the_member_service_and_flashes(): void
    {
        [$customer, $project, $service] = $this->deployedStack();

        $members = $this->mock(ContainerStackMemberService::class);
        $members->shouldReceive('restart')
            ->once()
            ->withArgs(fn (Service $s, string $key) => $s->is($service) && $key === 'db')
            ->andReturnUsing(function (Service $s) {
                $member = app(StackMemberResolver::class)->member($s, 'db');

                return new StackMemberActionResult($member, true, 3, StackMemberState::fromDocker('running', null, now()->toImmutable()));
            });

        $this->actingAs($customer)
            ->from(route('customer.projects.show', $project))
            ->post(route('customer.services.container.members.restart', [$service, 'db']))
            ->assertRedirect(route('customer.projects.show', $project))
            ->assertSessionHas('success', 'Database restarted.');
    }

    #[Test]
    public function another_customer_cannot_restart_a_member_and_a_bad_key_is_rejected(): void
    {
        [$customer, $project, $service] = $this->deployedStack();
        $stranger = User::factory()->customer()->create();
        $this->mock(ContainerStackMemberService::class)->shouldNotReceive('restart');

        $this->actingAs($stranger)
            ->post(route('customer.services.container.members.restart', [$service, 'db']))
            ->assertForbidden();

        $this->actingAs($customer)
            ->from(route('customer.projects.show', $project))
            ->post(route('customer.services.container.members.restart', [$service, 'db;rm']))
            ->assertNotFound();
    }

    #[Test]
    public function an_admin_reaches_the_same_action_through_the_admin_console_route(): void
    {
        [$customer, $project, $service] = $this->deployedStack();
        $admin = User::factory()->admin()->create();
        $members = $this->mock(ContainerStackMemberService::class);
        $members->shouldReceive('restart')->once()->andReturnUsing(function (Service $s) {
            $member = app(StackMemberResolver::class)->member($s, 'db');

            return new StackMemberActionResult($member, true, 0, StackMemberState::fromDocker('running', null, now()->toImmutable()));
        });

        $this->actingAs($admin)
            ->post(route('admin.services.container.members.restart', [$service, 'db']))
            ->assertRedirect();
    }

    /**
     * @param  array<string, string>  $states  container name => docker state
     */
    private function snapshot(ContainerDeployment $deployment, string $checkedAt, array $states): void
    {
        $at = Carbon::parse($checkedAt)->toIso8601String();
        $containers = [];
        foreach ($states as $name => $state) {
            $containers[$name] = ['state' => $state === 'exited' ? 'stopped' : $state, 'status' => null, 'checked_at' => $at, 'service' => null];
        }
        $deployment->forceFill([
            'member_states' => ['version' => 1, 'checked_at' => $at, 'reachable' => true, 'error' => null, 'error_at' => null, 'containers' => $containers],
            'member_states_checked_at' => $checkedAt,
        ])->save();
    }

    /**
     * @return array{0: User, 1: CustomerProject, 2: Service, 3: ContainerDeployment}
     */
    private function deployedStack(): array
    {
        $customer = User::factory()->customer()->create();
        $node = Node::factory()->containerHost()->create(['ssh_username' => 'root', 'ssh_password' => 'secret']);
        $product = Product::factory()->containerHosting()->create(['name' => 'App Hosting']);
        $project = CustomerProject::factory()->create(['user_id' => $customer->id, 'name' => 'Atlas']);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'name' => 'Atlas',
            'node_id' => $node->id,
            'provisioning_driver_key' => 'container',
        ]);
        $project->update(['billing_service_id' => $service->id]);
        $name = 'user-'.$customer->id.'-service-'.$service->id.'-laravel';
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => $name,
            'status' => 'running',
            'docker_compose_content' => sprintf(self::YAML, $name),
        ]);

        return [$customer, $project, $service->fresh(), $deployment];
    }
}
