<?php

namespace Tests\Feature\Reseller;

use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Customer\DeployTargetService;
use App\Services\ResellerProvisionProductResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reseller sells application hosting from their own package pools: they
 * write the specs of a plan themselves, the platform builds it on a shell
 * product, and the customer deploys onto it like any other plan.
 */
class ResellerApplicationHostingPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reseller_creates_an_application_hosting_plan_from_their_own_specs(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384, 'disk_pool_gb' => 200, 'bandwidth_pool_gb' => 2000]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Startup',
                'type' => 'container_hosting',
                'monthly_price' => 1500,
                'yearly_price' => 15000,
                'is_active' => true,
                'resource_limits' => ['cpu' => 1, 'memory_mb' => 2048, 'disk_gb' => 20, 'bandwidth_gb' => 200],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $listing = ResellerProduct::where('reseller_id', $reseller->id)->firstOrFail();

        $this->assertNull($listing->product_id, 'The plan is the reseller\'s own, not a copy of a platform product.');
        $this->assertTrue($listing->isResellerContainerPlan());
        $this->assertSame(['cpu' => 1.0, 'memory_mb' => 2048, 'disk_gb' => 20.0, 'bandwidth_gb' => 200.0], $listing->containerResourceLimits());
        $this->assertTrue($listing->isOrderable());

        $shell = $listing->provisionProduct();
        $this->assertNotNull($shell);
        $this->assertSame('container', $shell->provisioning_driver_key);
        $this->assertSame(ResellerProvisionProductResolver::CONTAINER_SHELL_PRODUCT_SLUG, $shell->slug);
        $this->assertFalse((bool) $shell->is_active, 'The shell never shows in the platform storefront.');
    }

    public function test_the_plan_form_is_the_pool_partial_and_the_page_still_renders(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 4, 'memory_pool_mb' => 8192]);

        $this->actingAs($reseller)
            ->get(route('reseller.catalog.create'))
            ->assertOk()
            ->assertSee('Plan specs')
            ->assertSee('resource_limits[cpu]', false)
            ->assertSee('resource_limits[bandwidth_gb]', false);
    }

    public function test_a_plan_cannot_promise_more_than_the_package_pool(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 2, 'memory_pool_mb' => 4096, 'disk_pool_gb' => 50, 'bandwidth_pool_gb' => 500]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Too big',
                'type' => 'container_hosting',
                'monthly_price' => 9000,
                'is_active' => true,
                'resource_limits' => ['cpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 100, 'bandwidth_gb' => 1000],
            ])
            ->assertSessionHasErrors(['resource_limits.cpu', 'resource_limits.memory_mb', 'resource_limits.disk_gb', 'resource_limits.bandwidth_gb']);

        $this->assertSame(0, ResellerProduct::where('reseller_id', $reseller->id)->count());
    }

    public function test_an_unmetered_package_accepts_any_specs(): void
    {
        $reseller = $this->reseller(['disk_pool_gb' => 0, 'storage_space' => 0]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Big',
                'type' => 'container_hosting',
                'monthly_price' => 9000,
                'is_active' => true,
                'resource_limits' => ['cpu' => 8, 'memory_mb' => 16384, 'disk_gb' => 500],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_a_pinned_stack_must_fit_the_plan(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $heavy = ContainerTemplate::factory()->create(['name' => 'Java', 'required_ram_mb' => 4096, 'required_cpu_cores' => 2]);

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Java Lite',
                'type' => 'container_hosting',
                'monthly_price' => 1000,
                'is_active' => true,
                'container_template_id' => $heavy->id,
                'resource_limits' => ['cpu' => 1, 'memory_mb' => 1024, 'disk_gb' => 10],
            ])
            ->assertSessionHasErrors('container_template_id');

        $this->actingAs($reseller)
            ->post(route('reseller.catalog.store'), [
                'name' => 'Java Pro',
                'type' => 'container_hosting',
                'monthly_price' => 3000,
                'is_active' => true,
                'container_template_id' => $heavy->id,
                'resource_limits' => ['cpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 20],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($heavy->id, ResellerProduct::where('name', 'Java Pro')->firstOrFail()->container_template_id);
    }

    public function test_the_reseller_edits_their_plan_specs_in_place(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $listing = $this->plan($reseller, cpu: 1, memoryMb: 1024);

        $this->actingAs($reseller)
            ->get(route('reseller.catalog.edit', $listing))
            ->assertOk()
            ->assertSee('Plan specs');

        $this->actingAs($reseller)
            ->put(route('reseller.catalog.update', $listing), [
                'name' => 'Startup',
                'type' => 'container_hosting',
                'monthly_price' => 1800,
                'is_active' => true,
                'resource_limits' => ['cpu' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'bandwidth_gb' => 0],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2.0, $listing->fresh()->containerResourceLimits()['cpu']);
        $this->assertSame(4096, $listing->fresh()->containerResourceLimits()['memory_mb']);
        $this->assertNull($listing->fresh()->product_id);
    }

    public function test_the_customer_deploy_hub_offers_the_resellers_plan(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        ContainerTemplate::factory()->create(['name' => 'Fits', 'required_ram_mb' => 1024, 'required_cpu_cores' => 1]);
        ContainerTemplate::factory()->create(['name' => 'Too heavy', 'required_ram_mb' => 4096, 'required_cpu_cores' => 2]);
        $listing = $this->plan($reseller, cpu: 1, memoryMb: 2048);

        $offers = app(DeployTargetService::class)->newPlans($customer);

        $this->assertCount(1, $offers);
        $offer = $offers->first();
        $this->assertSame($listing->id, $offer->resellerProductId);
        $this->assertSame('Startup', $offer->name);
        $this->assertSame(2048, (int) $offer->resourceLimits['memory_mb']);
        $this->assertNull($offer->pinnedTemplateId);

        // Seeded stacks share the picker, so count what fits rather than guess.
        $fitting = ContainerTemplate::offeredForNewDeploy()->get()
            ->filter(fn (ContainerTemplate $t) => (float) $t->required_cpu_cores <= 1 && (int) $t->required_ram_mb <= 2048)
            ->count();
        $this->assertGreaterThanOrEqual(1, $fitting);
        $this->assertSame($fitting, $offer->eligibleStackCount, 'Only stacks that fit 1 vCPU / 2 GB are offered.');
    }

    public function test_adding_a_service_on_an_open_plan_needs_a_stack_that_fits(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $laravel = ContainerTemplate::factory()->create(['name' => 'Laravel Test', 'slug' => 'laravel-test-stack', 'required_ram_mb' => 1024, 'required_cpu_cores' => 1]);
        $java = ContainerTemplate::factory()->create(['name' => 'Java Test', 'slug' => 'java-test-stack', 'required_ram_mb' => 4096, 'required_cpu_cores' => 2]);
        $listing = $this->plan($reseller, cpu: 1, memoryMb: 2048);
        Setting::setValue('auto_provision', 'false');
        Setting::setValue('reseller_auto_provision_hosting', 'false');

        $this->actingAs($reseller)
            ->post(route('reseller.customers.add-service', $customer), [
                'reseller_product_id' => $listing->id,
                'billing_cycle' => 'monthly',
                'order_type' => 'provision',
                'bill_customer' => '0',
            ])
            ->assertSessionHasErrors('container_template_id');

        $this->actingAs($reseller)
            ->post(route('reseller.customers.add-service', $customer), [
                'reseller_product_id' => $listing->id,
                'billing_cycle' => 'monthly',
                'order_type' => 'provision',
                'bill_customer' => '0',
                'container_template_id' => $java->id,
            ])
            ->assertSessionHasErrors('container_template_id');

        $this->assertSame(0, Service::where('user_id', $customer->id)->count());

        $this->actingAs($reseller)
            ->post(route('reseller.customers.add-service', $customer), [
                'reseller_product_id' => $listing->id,
                'billing_cycle' => 'monthly',
                'order_type' => 'provision',
                'bill_customer' => '0',
                'container_template_id' => $laravel->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('reseller.customers.show', $customer));

        $service = Service::where('user_id', $customer->id)->firstOrFail();
        $meta = $service->service_meta;

        $this->assertSame('container', $service->provisioning_driver_key);
        $this->assertSame('laravel-test-stack', $meta['provision_template_slug']);
        $this->assertSame('laravel-test-stack', $meta['language_slug']);
        $this->assertSame(2048, (int) $meta['reseller_catalog_limits']['memory_mb']);
        $this->assertTrue($meta['project_billing_anchor']);

        $project = CustomerProject::findOrFail($service->project_id);
        $this->assertSame(CustomerProject::PLAN_POOL_RECIPE, $project->recipe_key);
        $this->assertSame($service->id, $project->billing_service_id);
        $this->assertSame($listing->id, $project->resource_pool['reseller_product_id']);
        $this->assertSame(2048, (int) $project->includedPlanLimits()['memory_mb'], 'The plan pool is sized by the reseller\'s specs, not the shell product.');
        $this->assertSame(1.0, (float) $project->includedPlanLimits()['cpu']);
    }

    public function test_adding_a_service_on_a_pinned_plan_needs_no_stack_choice(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $wordpress = ContainerTemplate::factory()->create(['name' => 'WordPress Test', 'slug' => 'wordpress-test-stack', 'required_ram_mb' => 1024, 'required_cpu_cores' => 1]);
        $listing = $this->plan($reseller, cpu: 1, memoryMb: 2048, templateId: $wordpress->id);
        Setting::setValue('auto_provision', 'false');
        Setting::setValue('reseller_auto_provision_hosting', 'false');

        $this->actingAs($reseller)
            ->post(route('reseller.customers.add-service', $customer), [
                'reseller_product_id' => $listing->id,
                'billing_cycle' => 'monthly',
                'order_type' => 'provision',
                'bill_customer' => '0',
            ])
            ->assertSessionHasNoErrors();

        $service = Service::where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('wordpress-test-stack', $service->service_meta['provision_template_slug']);
    }

    public function test_the_customer_page_lists_stacks_for_the_picker(): void
    {
        $reseller = $this->reseller(['cpu_pool_cores' => 8, 'memory_pool_mb' => 16384]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        ContainerTemplate::factory()->create(['name' => 'Laravel Stack']);
        $this->plan($reseller, cpu: 1, memoryMb: 2048);

        $this->actingAs($reseller)
            ->get(route('reseller.customers.show', $customer))
            ->assertOk()
            ->assertSee('container_template_id', false)
            ->assertSee('Laravel Stack');
    }

    private function reseller(array $packageOverrides = []): User
    {
        $package = ResellerPackage::create(array_merge([
            'name' => 'Pool '.uniqid(),
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'disk_pool_gb' => 100,
            'max_services' => 0,
            'max_users' => 0,
            'price' => 1000,
            'active' => true,
        ], $packageOverrides));

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now()->subMonth(),
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function plan(User $reseller, float $cpu, int $memoryMb, ?int $templateId = null): ResellerProduct
    {
        return ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Startup',
            'type' => 'container_hosting',
            'monthly_price' => 1500,
            'is_active' => true,
            'container_template_id' => $templateId,
            'resource_limits' => ['cpu' => $cpu, 'memory_mb' => $memoryMb, 'disk_gb' => 20],
        ]);
    }
}
