<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\InvoiceGenerationScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHostingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ContainerTemplate, 1: ContainerTemplate}
     */
    private function seedLaravelAndNodeTemplates(): array
    {
        $laravel = ContainerTemplate::query()->updateOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel',
                'description' => 'Laravel app',
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
                'description' => 'Node app',
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

        return [$laravel, $nodejs];
    }

    public function test_laravel_next_checkout_creates_project_with_two_services_and_one_invoice_item(): void
    {
        $customer = User::factory()->customer()->create();

        [$laravel] = $this->seedLaravelAndNodeTemplates();

        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'name' => 'Growth',
            'monthly_price' => 2500,
            'is_active' => true,
            'container_template_id' => $laravel->id,
            'provisioning_driver_key' => 'container',
        ]);

        session([
            CheckoutController::CART_SESSION_KEY => [
                'item-1' => [
                    'type' => 'product',
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'name' => $product->name,
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
        $project = CustomerProject::where('user_id', $customer->id)->first();
        $this->assertSame('laravel_next', $project->recipe_key);

        $services = Service::where('user_id', $customer->id)->where('product_id', $product->id)->get();
        $this->assertCount(2, $services);

        $backend = $services->first(fn (Service $s) => ($s->service_meta['project_role'] ?? null) === 'backend');
        $frontend = $services->first(fn (Service $s) => ($s->service_meta['project_role'] ?? null) === 'frontend');

        $this->assertNotNull($backend);
        $this->assertNotNull($frontend);
        $this->assertTrue((bool) ($backend->service_meta['project_billing_anchor'] ?? false));
        $this->assertFalse((bool) ($frontend->service_meta['project_billing_anchor'] ?? false));
        $this->assertSame($project->id, $backend->project_id);
        $this->assertSame($project->id, $frontend->project_id);
        $this->assertSame($backend->id, $project->billing_service_id);
        $this->assertSame('nodejs', $frontend->service_meta['provision_template_slug'] ?? null);

        $invoice = Invoice::where('user_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($invoice);

        $hostingItems = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('product_id', $product->id)
            ->get();

        $this->assertCount(1, $hostingItems);
        $this->assertSame($backend->id, $hostingItems->first()->service_id);
        $this->assertStringContainsString('API', $hostingItems->first()->description);
        $this->assertStringContainsString('Web', $hostingItems->first()->description);
    }

    public function test_non_anchor_project_role_is_not_due_for_renewal_invoice(): void
    {
        $customer = User::factory()->customer()->create();
        [$laravel] = $this->seedLaravelAndNodeTemplates();
        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $laravel->id,
            'monthly_price' => 1000,
            'is_active' => true,
        ]);

        $frontend = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addDay(),
            'custom_price' => 0,
            'service_meta' => [
                'project_recipe' => 'laravel_next',
                'project_role' => 'frontend',
                'project_billing_anchor' => false,
            ],
        ]);

        $this->assertFalse(
            app(InvoiceGenerationScheduleService::class)->isServiceDueForRenewalInvoice($frontend)
        );
    }

    public function test_confirm_techstack_mentions_project_billing_for_laravel_next(): void
    {
        $customer = User::factory()->customer()->create();
        [$laravel] = $this->seedLaravelAndNodeTemplates();
        Product::factory()->create([
            'type' => 'container_hosting',
            'container_template_id' => $laravel->id,
            'is_active' => true,
            'monthly_price' => 1000,
            'provisioning_driver_key' => 'container',
        ]);

        session([
            'selected_techstack' => [
                'language_id' => $laravel->id,
                'language_name' => 'Laravel',
                'language_slug' => 'laravel',
                'backend' => 'laravel',
                'framework' => 'laravel',
                'frontend' => 'nextjs',
                'hosting_type' => 'container',
            ],
        ]);

        $this->actingAs($customer)
            ->get(route('customer.confirm-techstack'))
            ->assertOk()
            ->assertSee('billed as a single plan', false);
    }
}
