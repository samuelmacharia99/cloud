<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SharedStackPlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ContainerTemplate, 1: ContainerTemplate, 2: Product}
     */
    private function seedStacksAndSharedPlan(): array
    {
        $laravel = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel',
                'category' => 'web',
                'docker_image' => 'laravel:latest',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 5,
                'is_active' => true,
                'order' => 1,
            ]
        );

        $nodejs = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'nodejs'],
            [
                'name' => 'Node.js',
                'description' => 'Node',
                'category' => 'web',
                'docker_image' => 'node:20',
                'default_port' => 3000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1,
                'required_storage_gb' => 5,
                'is_active' => true,
                'order' => 2,
            ]
        );

        $plan = Product::factory()->create([
            'type' => 'container_hosting',
            'name' => 'Project Growth',
            'slug' => 'project-growth',
            'is_active' => true,
            'container_template_id' => null,
            'provisioning_driver_key' => 'container',
            'monthly_price' => 2500,
            'resource_limits' => [
                'cpu' => 2,
                'memory' => 4096,
                'disk' => 500,
            ],
        ]);

        return [$laravel, $nodejs, $plan];
    }

    public function test_shared_plan_appears_for_laravel_and_nodejs_confirm_pages(): void
    {
        $customer = User::factory()->customer()->create();
        [$laravel, $nodejs, $plan] = $this->seedStacksAndSharedPlan();

        session([
            'selected_techstack' => [
                'language_id' => $laravel->id,
                'language_name' => 'Laravel',
                'language_slug' => 'laravel',
                'frontend' => null,
                'hosting_type' => 'container',
            ],
        ]);

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertOk()
            ->assertSee('Project Growth', false);

        session([
            'selected_techstack' => [
                'language_id' => $nodejs->id,
                'language_name' => 'Node.js',
                'language_slug' => 'nodejs',
                'frontend' => null,
                'hosting_type' => 'container',
            ],
        ]);

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertOk()
            ->assertSee('Project Growth', false);

        $this->assertTrue($plan->fresh()->isSharedStackPlan());
    }

    public function test_confirm_techstack_lists_packages_cheapest_first(): void
    {
        $customer = User::factory()->customer()->create();
        [$laravel] = $this->seedStacksAndSharedPlan();

        Product::factory()->create([
            'type' => 'container_hosting',
            'name' => 'Project Pro',
            'slug' => 'project-pro',
            'is_active' => true,
            'container_template_id' => null,
            'monthly_price' => 999,
            'order' => 1,
        ]);

        Product::factory()->create([
            'type' => 'container_hosting',
            'name' => 'Project Starter',
            'slug' => 'project-starter',
            'is_active' => true,
            'container_template_id' => null,
            'monthly_price' => 450,
            'order' => 99,
            'resource_limits' => [
                'cpu' => 1,
                'memory' => 2048,
                'disk' => 50,
            ],
        ]);

        session([
            'selected_techstack' => [
                'language_id' => $laravel->id,
                'language_name' => 'Laravel',
                'language_slug' => 'laravel',
                'frontend' => null,
                'hosting_type' => 'container',
            ],
        ]);

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertOk()
            ->assertSeeInOrder([
                'Project Starter',
                'Project Pro',
                'Project Growth',
            ], false)
            ->assertSee('Select a plan', false)
            ->assertSee('CPU', false)
            ->assertSee('RAM', false)
            ->assertSee('Disk', false)
            ->assertSee('2 GB', false);
    }

    public function test_shared_plan_checkout_stores_stack_meta_and_resolves_template(): void
    {
        $customer = User::factory()->customer()->create();
        [$laravel, $nodejs, $plan] = $this->seedStacksAndSharedPlan();

        session([
            CheckoutController::CART_SESSION_KEY => [
                'item-1' => [
                    'type' => 'product',
                    'product_id' => $plan->id,
                    'billing_cycle' => 'monthly',
                    'name' => $plan->name,
                    'unit_price' => 2500,
                    'amount' => 2500,
                ],
            ],
            'selected_techstack' => [
                'language_id' => $nodejs->id,
                'language_name' => 'Node.js',
                'language_slug' => 'nodejs',
                'backend' => 'nodejs',
                'hosting_type' => 'container',
                'deployment_platform' => 'container',
            ],
        ]);

        $this->actingAs($customer)
            ->post(route('customer.checkout.process'), [
                'agree_terms' => '1',
            ])
            ->assertRedirect();

        $service = Service::where('user_id', $customer->id)->where('product_id', $plan->id)->first();
        $this->assertNotNull($service);
        $this->assertSame($nodejs->id, (int) ($service->service_meta['container_template_id'] ?? 0));
        $this->assertSame('nodejs', $service->service_meta['language_slug'] ?? null);

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'resolveContainerTemplate');
        $method->setAccessible(true);
        $resolved = $method->invoke(app(ContainerDeploymentService::class), $service->fresh(['product']));

        $this->assertNotNull($resolved);
        $this->assertSame('nodejs', $resolved->slug);
        $this->assertSame($nodejs->id, $resolved->id);
    }

    public function test_shared_plan_laravel_next_still_expands_project(): void
    {
        $customer = User::factory()->customer()->create();
        [$laravel, , $plan] = $this->seedStacksAndSharedPlan();

        session([
            CheckoutController::CART_SESSION_KEY => [
                'item-1' => [
                    'type' => 'product',
                    'product_id' => $plan->id,
                    'billing_cycle' => 'monthly',
                    'name' => $plan->name,
                    'unit_price' => 2500,
                    'amount' => 2500,
                ],
            ],
            'selected_techstack' => [
                'language_id' => $laravel->id,
                'language_name' => 'Laravel',
                'language_slug' => 'laravel',
                'backend' => 'laravel',
                'framework' => 'laravel',
                'frontend' => 'nextjs',
                'hosting_type' => 'container',
                'deployment_platform' => 'container',
            ],
        ]);

        $this->actingAs($customer)
            ->post(route('customer.checkout.process'), [
                'agree_terms' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(1, CustomerProject::where('user_id', $customer->id)->count());
        $services = Service::where('user_id', $customer->id)->where('product_id', $plan->id)->get();
        $this->assertCount(2, $services);

        $backend = $services->first(fn (Service $s) => ($s->service_meta['project_role'] ?? null) === 'backend');
        $this->assertSame('laravel', $backend->service_meta['provision_template_slug'] ?? null);

        $items = InvoiceItem::where('service_id', $backend->id)->get();
        $this->assertCount(1, $items);
    }
}
