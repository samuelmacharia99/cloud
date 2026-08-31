<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_next_service_gets_a_project_with_container_labels(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create(['name' => 'App Hosting']);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Atlas',
            'status' => 'active',
            'service_meta' => [
                'frontend' => 'nextjs',
                'backend' => 'laravel',
                'database_id' => 'mysql',
            ],
        ]);

        $response = $this->actingAs($customer)->get(route('customer.services.index'));

        $response->assertOk();
        $response->assertSee('Atlas');
        $response->assertSee('Rename project');

        $project = CustomerProject::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($project);

        $this->actingAs($customer)
            ->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('Backend')
            ->assertSee('Frontend')
            ->assertSee('Edge')
            ->assertSee('Database');
        $this->assertNotNull(Service::query()->where('name', 'Atlas')->value('project_id'));
    }

    public function test_related_services_are_grouped_under_a_project(): void
    {
        $customer = User::factory()->customer()->create();
        $appProduct = Product::factory()->containerHosting()->create(['name' => 'App Hosting']);
        $emailProduct = Product::factory()->emailHosting()->create(['name' => 'Email Hosting']);

        $app = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $appProduct->id,
            'name' => 'Washflow App',
            'status' => 'active',
            'service_meta' => [],
        ]);

        $email = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $emailProduct->id,
            'name' => 'Washflow Mail',
            'status' => 'active',
        ]);

        $app->update([
            'service_meta' => ['bundled_email_service_id' => $email->id],
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Rename project');

        $project = CustomerProject::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($project);

        $this->actingAs($customer)
            ->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('Washflow App')
            ->assertSee('Washflow Mail');

        $this->assertSame(
            $app->fresh()->project_id,
            $email->fresh()->project_id
        );
    }

    public function test_sidecar_compose_shows_container_labels(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Atlas',
            'status' => 'active',
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'docker_compose_content' => <<<'YAML'
services:
  backend:
    image: php:8.3
  frontend:
    image: node:20
  edge:
    image: alpine
  db:
    image: mysql:8
YAML,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Rename project');

        $project = CustomerProject::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($project);

        $this->actingAs($customer)
            ->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('Backend')
            ->assertSee('Frontend')
            ->assertSee('Edge')
            ->assertSee('Database');
    }

    public function test_customer_can_create_project_and_move_service(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'shared_hosting']);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Solo Shared',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->post(route('customer.projects.store'), ['name' => 'Client A'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $project = CustomerProject::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($project);
        $this->assertSame('Client A', $project->name);

        $this->actingAs($customer)
            ->patch(route('customer.services.project', $service), [
                'project_id' => $project->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($project->id, $service->fresh()->project_id);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Client A')
            ->assertSee('Owner')
            ->assertSee('1 Resource');

        $this->actingAs($customer)
            ->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('Client A')
            ->assertSee('Solo Shared');

        $this->actingAs($customer)
            ->patchJson(route('customer.services.project', $service), [
                'project_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($service->fresh()->project_id);
    }

    public function test_customer_can_rename_own_project(): void
    {
        $customer = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Project',
        ]);
        $product = Product::factory()->containerHosting()->create();
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->patch(route('customer.projects.rename', $project), ['name' => 'Washflow'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Washflow', $project->fresh()->name);
    }

    public function test_customer_cannot_rename_another_users_project(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Project',
        ]);

        $this->actingAs($other)
            ->patch(route('customer.projects.rename', $project), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Owner Project', $project->fresh()->name);
    }

    public function test_single_service_without_multiple_containers_stays_ungrouped(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'shared_hosting', 'name' => 'Shared']);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Solo Shared',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Solo Shared')
            ->assertSee('No project')
            ->assertDontSee('Rename project');

        $this->assertDatabaseCount('customer_projects', 0);
    }

    public function test_customer_can_remove_an_application_hosting_project_and_its_sites(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'sigtuna.org',
        ]);
        $primary = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'name' => 'sigtuna.org',
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);
        $sibling = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'name' => 'app.sigtuna.org',
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Remove project');

        $this->actingAs($customer)
            ->delete(route('customer.projects.destroy', $project), [
                'confirm_name' => 'sigtuna.org',
                'confirm' => '1',
            ])
            ->assertRedirect(route('customer.services.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('customer_projects', ['id' => $project->id]);
        $this->assertSame('terminated', $primary->fresh()->status->value ?? $primary->fresh()->status);
        $this->assertSame('terminated', $sibling->fresh()->status->value ?? $sibling->fresh()->status);
        $this->assertDatabaseHas('tickets', [
            'user_id' => $customer->id,
            'title' => 'Project removed: sigtuna.org',
        ]);
    }

    public function test_customer_cannot_remove_project_when_it_also_has_email_hosting(): void
    {
        $customer = User::factory()->customer()->create();
        $appProduct = Product::factory()->containerHosting()->create();
        $emailProduct = Product::factory()->emailHosting()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Washflow',
        ]);
        $app = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $appProduct->id,
            'project_id' => $project->id,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $emailProduct->id,
            'project_id' => $project->id,
            'name' => 'Washflow Mail',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->delete(route('customer.projects.destroy', $project), [
                'confirm_name' => 'Washflow',
                'confirm' => '1',
            ])
            ->assertSessionHasErrors('confirm_name');

        $this->assertDatabaseHas('customer_projects', ['id' => $project->id]);
        $this->assertSame('active', $app->fresh()->status->value ?? $app->fresh()->status);
    }

    public function test_customer_cannot_remove_another_users_project(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Project',
        ]);

        $this->actingAs($other)
            ->delete(route('customer.projects.destroy', $project), [
                'confirm_name' => 'Owner Project',
                'confirm' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('customer_projects', ['id' => $project->id]);
    }

    public function test_empty_projects_appear_as_cards_on_services_index(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'shared_hosting', 'name' => 'Shared']);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Solo Shared',
            'status' => 'active',
        ]);
        CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Orphan Empty Project',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('Solo Shared')
            ->assertSee('Orphan Empty Project')
            ->assertSee('Choose a plan')
            ->assertSee('New project');
    }

    public function test_customer_cannot_view_another_users_project(): void
    {
        $owner = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Project',
        ]);

        $this->actingAs($other)
            ->get(route('customer.projects.show', $project))
            ->assertForbidden();
    }

    public function test_create_project_redirects_to_project_show_page(): void
    {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)
            ->post(route('customer.projects.store'), ['name' => 'New Workspace']);

        $project = CustomerProject::query()->where('user_id', $customer->id)->where('name', 'New Workspace')->first();
        $this->assertNotNull($project);

        $response->assertRedirect(route('customer.projects.show', $project));
    }

    public function test_admin_with_customer_assets_can_view_project_show(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $admin->id,
            'name' => 'Admin Project',
        ]);
        Service::factory()->create([
            'user_id' => $admin->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'name' => 'Admin App',
        ]);

        $this->actingAs($admin)
            ->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('Admin Project')
            ->assertSee('Admin App');
    }

    public function test_admin_without_customer_assets_is_redirected_from_services_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('customer.services.index'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_billed_project_card_offers_included_deploy(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create(['name' => 'App Hosting']);
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'LS Production',
        ]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index'))
            ->assertOk()
            ->assertSee('LS Production')
            ->assertSee('Owner')
            ->assertSee('1 Resource')
            ->assertSee('Deploy new service')
            ->assertSee('Open project')
            ->assertSee('aria-label="Rename project"', false);

        $this->actingAs($customer)
            ->get(route('customer.projects.show', $project))
            ->assertOk()
            ->assertSee('LS Production')
            ->assertSee('Deploy new service')
            ->assertSee('not billed again');
    }
}
