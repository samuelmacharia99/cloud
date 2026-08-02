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

    public function test_related_services_are_grouped_under_a_project_folder(): void
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

        $response = $this->actingAs($customer)->get(route('customer.services.index'));

        $response->assertOk();
        $response->assertSee('Project');
        $response->assertSee('Rename project');
        $response->assertSee('Washflow App');
        $response->assertSee('Washflow Mail');

        $this->assertSame(
            $app->fresh()->project_id,
            $email->fresh()->project_id
        );
        $this->assertNotNull($app->fresh()->project_id);
    }

    public function test_sidecar_stack_shows_project_folder_with_container_labels(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create(['name' => 'App Hosting']);
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

        $response = $this->actingAs($customer)->get(route('customer.services.index'));

        $response->assertOk();
        $response->assertSee('Project');
        $response->assertSee('Rename project');
        $response->assertSee('Backend');
        $response->assertSee('Frontend');
        $response->assertSee('Edge');
        $response->assertSee('Database');
        $response->assertSee('Atlas');
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

        $response = $this->actingAs($customer)->patch(route('customer.projects.rename', $project), [
            'name' => 'Washflow',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
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

        $this->actingAs($other)->patch(route('customer.projects.rename', $project), [
            'name' => 'Hijacked',
        ])->assertForbidden();

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

        $response = $this->actingAs($customer)->get(route('customer.services.index'));

        $response->assertOk();
        $response->assertSee('Solo Shared');
        $response->assertDontSee('Rename project');
        $this->assertDatabaseCount('customer_projects', 0);
    }

    public function test_project_switcher_filters_resources(): void
    {
        $customer = User::factory()->customer()->create();
        $appProduct = Product::factory()->containerHosting()->create();
        $emailProduct = Product::factory()->emailHosting()->create();
        $sharedProduct = Product::factory()->create(['type' => 'shared_hosting']);

        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'Washflow',
        ]);

        $app = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $appProduct->id,
            'project_id' => $project->id,
            'name' => 'Washflow App',
            'status' => 'active',
            'service_meta' => [],
        ]);
        $email = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $emailProduct->id,
            'project_id' => $project->id,
            'name' => 'Washflow Mail',
            'status' => 'active',
        ]);
        $app->update(['service_meta' => ['bundled_email_service_id' => $email->id]]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $sharedProduct->id,
            'name' => 'Solo Shared',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.index', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('Washflow App')
            ->assertSee('Washflow Mail')
            ->assertDontSee('Solo Shared')
            ->assertSee('Rename project');

        $this->actingAs($customer)
            ->get(route('customer.services.index', ['project' => 'ungrouped']))
            ->assertOk()
            ->assertSee('Solo Shared')
            ->assertDontSee('Washflow App');
    }
}
