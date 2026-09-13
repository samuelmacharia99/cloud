<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deploy page asks where the service runs before it asks what it is:
 * an existing plan with room deploys at once, a new plan goes to the cart
 * with the stack the customer picks for it, and the stack picker only
 * offers what the chosen plan can run.
 */
class DeployServiceHubTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_customer_sees_only_plans_to_buy(): void
    {
        $customer = User::factory()->customer()->create();
        Product::factory()->containerHosting()->create(['name' => 'Starter App', 'monthly_price' => 9.99, 'resource_limits' => ['cpu' => 1, 'memory' => 1024, 'disk' => 10]]);
        Product::factory()->containerHosting()->create(['name' => 'Pro App', 'monthly_price' => 29.99, 'resource_limits' => ['cpu' => 2, 'memory' => 4096, 'disk' => 40]]);

        $this->actingAs($customer)->get(route('customer.deploy-service'))
            ->assertOk()
            ->assertSee('Where should this run?')
            ->assertSee('Buy a new plan')
            ->assertDontSee('Deploy on an existing plan')
            ->assertSeeInOrder(['Starter App', 'Pro App'])
            ->assertSee(route('customer.deploy-service.plan'), false);
    }

    #[Test]
    public function a_plan_with_room_is_offered_and_a_full_one_is_disabled_with_an_upgrade(): void
    {
        [$customer, $project, $anchor] = $this->billedProject();
        [, $fullProject, $fullAnchor] = $this->billedProject($customer, 'Crowded', [
            ['cpu' => 0.55, 'memory' => 0.55],
            ['cpu' => 0.25, 'memory' => 0.25],
        ]);

        $response = $this->actingAs($customer)->get(route('customer.deploy-service'))->assertOk();

        $response->assertSee('Deploy on an existing plan')
            ->assertSee('data-deploy-target="'.$project->id.'" data-has-room="1"', false)
            ->assertSee(route('customer.projects.deploy', $project), false)
            ->assertSee('data-deploy-target="'.$fullProject->id.'" data-has-room="0"', false)
            ->assertSee('This plan is full')
            ->assertSee(route('customer.services.upgrade', $fullAnchor), false)
            ->assertDontSee(route('customer.projects.deploy', $fullProject), false);
    }

    #[Test]
    public function choosing_a_plan_then_a_stack_lands_in_the_cart_with_that_line(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create(['name' => 'Starter App', 'monthly_price' => 9.99, 'resource_limits' => ['cpu' => 1, 'memory' => 2048, 'disk' => 10]]);
        $language = ContainerTemplate::factory()->create(['slug' => 'static-site', 'name' => 'Static site', 'is_active' => true, 'hosting_type' => 'container', 'required_ram_mb' => 256, 'required_cpu_cores' => 0.5]);

        $this->actingAs($customer)
            ->post(route('customer.deploy-service.plan'), ['product_id' => $product->id, 'billing_cycle' => 'annual'])
            ->assertRedirect(route('customer.deploy-service.stack'));

        $this->assertSame($product->id, session('selected_plan.product_id'));
        $this->assertSame('annual', session('selected_plan.billing_cycle'));

        $this->actingAs($customer)->get(route('customer.deploy-service.stack'))
            ->assertOk()
            ->assertSee('Choose a stack for Starter App')
            ->assertSee('Add to cart')
            ->assertSee('data-stack-eligible="1"', false);

        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), [
                'language_id' => $language->id,
                'frontend' => 'static',
                'deployment_platform' => 'container',
            ])
            ->assertRedirect(route('customer.cart.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Starter App with Static site added to your cart'));

        $lines = array_values(SessionCart::portal());
        $this->assertCount(1, $lines);
        $this->assertSame('product', $lines[0]['type']);
        $this->assertSame($product->id, (int) $lines[0]['product_id']);
        $this->assertSame('annual', $lines[0]['billing_cycle']);
        $this->assertSame('static-site', session('selected_techstack.language_slug'));
        $this->assertNull(session('selected_plan'));
    }

    #[Test]
    public function the_stack_picker_greys_out_stacks_the_plan_cannot_run(): void
    {
        $customer = User::factory()->customer()->create();
        $small = Product::factory()->containerHosting()->create(['name' => 'Tiny', 'resource_limits' => ['cpu' => 1, 'memory' => 1024, 'disk' => 10]]);
        $wordpress = ContainerTemplate::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress', 'is_active' => true, 'hosting_type' => 'container', 'required_ram_mb' => 512, 'required_cpu_cores' => 0.5]);
        $heavy = ContainerTemplate::factory()->create(['slug' => 'heavy-erp', 'name' => 'Heavy ERP', 'is_active' => true, 'hosting_type' => 'container', 'required_ram_mb' => 4096, 'required_cpu_cores' => 2]);

        $this->actingAs($customer)->post(route('customer.deploy-service.plan'), ['product_id' => $small->id, 'billing_cycle' => 'monthly']);
        $page = $this->actingAs($customer)->get(route('customer.deploy-service.stack'))->assertOk()->getContent();

        $this->assertStringContainsString('Needs 4 GB RAM; this plan includes 1 GB.', $page);
        $this->assertMatchesRegularExpression('/data-stack-eligible="0"[^>]*>[\s\S]*?Heavy ERP/', $page);
        $this->assertMatchesRegularExpression('/data-stack-eligible="1"[^>]*>[\s\S]*?WordPress/', $page);

        // Posting the heavy stack anyway is refused: the plan is not among its products.
        $this->actingAs($customer)
            ->post(route('customer.confirm-techstack.store'), ['language_id' => $heavy->id, 'deployment_platform' => 'container'])
            ->assertRedirect(route('customer.deploy-service.stack'))
            ->assertSessionHas('error', fn (string $e) => str_contains($e, 'cannot run Heavy ERP'));
        $this->assertSame([], SessionCart::portal());
    }

    #[Test]
    public function a_plan_pinned_to_one_stack_offers_only_that_stack(): void
    {
        $customer = User::factory()->customer()->create();
        $wordpress = ContainerTemplate::factory()->create(['slug' => 'wordpress', 'name' => 'WordPress', 'is_active' => true, 'hosting_type' => 'container']);
        ContainerTemplate::factory()->create(['slug' => 'nodejs', 'name' => 'Node.js', 'is_active' => true, 'hosting_type' => 'container']);
        $pinned = Product::factory()->containerHosting()->create(['name' => 'WP Only', 'container_template_id' => $wordpress->id, 'resource_limits' => ['cpu' => 2, 'memory' => 4096, 'disk' => 40]]);

        $this->actingAs($customer)->get(route('customer.deploy-service'))->assertOk()->assertSee('Runs WordPress only.');

        $this->actingAs($customer)->post(route('customer.deploy-service.plan'), ['product_id' => $pinned->id, 'billing_cycle' => 'monthly']);
        $page = $this->actingAs($customer)->get(route('customer.deploy-service.stack'))->assertOk()->getContent();

        $this->assertStringContainsString('This plan is for WordPress only.', $page);
        $this->assertMatchesRegularExpression('/data-stack-eligible="0"[^>]*>[\s\S]*?Node\.js/', $page);
    }

    #[Test]
    public function a_plan_the_customer_may_not_buy_is_refused(): void
    {
        $customer = User::factory()->customer()->create();
        $inactive = Product::factory()->containerHosting()->create(['is_active' => false]);
        $shared = Product::factory()->create(['type' => 'shared_hosting', 'is_active' => true]);

        foreach ([$inactive, $shared] as $product) {
            $this->actingAs($customer)
                ->post(route('customer.deploy-service.plan'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])
                ->assertRedirect(route('customer.deploy-service'))
                ->assertSessionHas('error');
        }

        $this->actingAs($customer)->get(route('customer.deploy-service.stack'))
            ->assertRedirect(route('customer.deploy-service'));
    }

    #[Test]
    public function a_reseller_customer_is_kept_on_the_reseller_catalog(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->containerHosting()->create();
        ResellerProduct::create(['reseller_id' => $reseller->id, 'product_id' => $product->id, 'name' => 'Reseller App', 'type' => 'container_hosting', 'monthly_price' => 15, 'is_active' => true]);

        $this->actingAs($customer)->get(route('customer.deploy-service'))->assertRedirect(route('customer.catalog.index'));
        $this->actingAs($customer)->post(route('customer.deploy-service.plan'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertRedirect(route('customer.catalog.index'));
        $this->actingAs($customer)->get(route('customer.deploy-service.stack'))->assertRedirect(route('customer.catalog.index'));
    }

    #[Test]
    public function an_unbilled_project_is_sent_to_the_deploy_page_and_the_plan_comes_back_attached(): void
    {
        $customer = User::factory()->customer()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id, 'name' => 'Fresh']);
        $product = Product::factory()->containerHosting()->create(['name' => 'Starter App', 'resource_limits' => ['cpu' => 1, 'memory' => 2048, 'disk' => 10]]);
        $language = ContainerTemplate::factory()->create(['slug' => 'static-site', 'name' => 'Static site', 'is_active' => true, 'hosting_type' => 'container']);

        $this->actingAs($customer)->get(route('customer.projects.deploy', $project))
            ->assertRedirect(route('customer.deploy-service', ['project' => $project->id]));

        $this->actingAs($customer)->get(route('customer.deploy-service', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('Choose a plan for Fresh')
            ->assertSee('name="project_id" value="'.$project->id.'"', false);

        $this->actingAs($customer)->post(route('customer.deploy-service.plan'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'project_id' => $project->id]);
        $this->actingAs($customer)->post(route('customer.confirm-techstack.store'), ['language_id' => $language->id, 'frontend' => 'static', 'deployment_platform' => 'container'])
            ->assertRedirect(route('customer.cart.index'));

        $this->assertSame($project->id, session('selected_techstack.project_id'));
    }

    /**
     * @param  list<array{cpu: float, memory: float}>  $workloadShares  extra included services already on the plan
     * @return array{0: User, 1: CustomerProject, 2: Service}
     */
    private function billedProject(?User $customer = null, string $name = 'Atlas', array $workloadShares = []): array
    {
        $customer ??= User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create(['name' => 'App Hosting', 'resource_limits' => ['cpu' => 2, 'memory' => 4096, 'disk' => 40]]);
        $project = CustomerProject::factory()->create(['user_id' => $customer->id, 'name' => $name]);
        $anchor = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => ['project_billing_anchor' => true],
        ]);
        $project->update(['billing_service_id' => $anchor->id]);

        foreach ($workloadShares as $share) {
            Service::factory()->create([
                'user_id' => $customer->id,
                'product_id' => $product->id,
                'project_id' => $project->id,
                'status' => 'active',
                'service_meta' => ['project_role' => 'workload', 'included_on_project_plan' => true, 'resource_share' => $share],
            ]);
        }

        return [$customer, $project->fresh(), $anchor];
    }
}
