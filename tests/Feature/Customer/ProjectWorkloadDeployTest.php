<?php

namespace Tests\Feature\Customer;

use App\Jobs\ProvisionContainerServiceJob;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ProjectRecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProjectWorkloadDeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_deploy_an_included_service_without_an_invoice(): void
    {
        [$customer, $project, $anchor, $language] = $this->makeBilledProject();

        Bus::fake();

        $invoiceCount = Invoice::query()->count();

        $this->actingAs($customer)
            ->get(route('customer.projects.deploy', $project))
            ->assertOk()
            ->assertSee('Deploy into '.$project->name)
            ->assertSee('not billed again')
            ->assertSee('Ollama');

        $response = $this->actingAs($customer)
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => $language->id,
                'frontend' => 'static',
            ]);

        $extra = Service::query()
            ->where('project_id', $project->id)
            ->where('id', '!=', $anchor->id)
            ->first();

        $this->assertNotNull($extra);
        $response->assertRedirect(route('customer.services.deploying', $extra));
        Bus::assertDispatched(
            ProvisionContainerServiceJob::class,
            fn (ProvisionContainerServiceJob $job) => $job->serviceId === $extra->id
        );
        $this->assertSame('provisioning', $extra->status->value ?? $extra->status);
        $this->assertSame(0.0, (float) $extra->custom_price);
        $this->assertNull($extra->invoice_id);
        $this->assertSame($anchor->product_id, $extra->product_id);
        $this->assertSame($anchor->billing_cycle, $extra->billing_cycle);
        $this->assertFalse((bool) $extra->service_meta['project_billing_anchor']);
        $this->assertTrue((bool) ($extra->service_meta['included_on_project_plan'] ?? false));
        $this->assertSame(0.25, (float) $extra->service_meta['resource_share']['cpu']);
        $this->assertSame(0.25, (float) $extra->service_meta['resource_share']['memory']);
        $this->assertTrue(app(ProjectRecipeService::class)->shouldSkipRenewalInvoice($extra->service_meta));
        $this->assertSame($invoiceCount, Invoice::query()->count());
        $this->assertSame($project->fresh()->billing_service_id, $anchor->id);
    }

    public function test_included_ollama_deploy_persists_model_size(): void
    {
        [$customer, $project] = $this->makeBilledProject();
        $ollama = ContainerTemplate::query()->where('slug', 'ollama')->firstOrFail();

        Bus::fake();

        $response = $this->actingAs($customer)
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => $ollama->id,
                'selected_version' => '8b',
            ]);

        $extra = Service::query()
            ->where('project_id', $project->id)
            ->where('id', '!=', $project->billing_service_id)
            ->first();

        $this->assertNotNull($extra);
        $response->assertRedirect(route('customer.services.deploying', $extra));
        $this->assertSame('ollama', $extra->service_meta['language_slug']);
        $this->assertSame('8b', $extra->service_meta['selected_version']);
        $this->assertSame('provisioning', $extra->status->value ?? $extra->status);

        $this->actingAs($customer)
            ->get(route('customer.services.deploying', $extra))
            ->assertOk()
            ->assertSee('Live console')
            ->assertSee('Pull the image and start the runtime');

        $this->actingAs($customer)
            ->getJson(route('customer.services.deploying.status', $extra))
            ->assertOk()
            ->assertJsonPath('status', 'provisioning')
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('redirect', null);
    }

    public function test_included_ollama_deploy_requires_model_size(): void
    {
        [$customer, $project] = $this->makeBilledProject();
        $ollama = ContainerTemplate::query()->where('slug', 'ollama')->firstOrFail();

        $this->actingAs($customer)
            ->from(route('customer.projects.deploy', $project))
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => $ollama->id,
            ])
            ->assertSessionHasErrors('selected_version');

        $this->assertSame(1, Service::query()->where('project_id', $project->id)->count());
    }

    public function test_included_deploy_is_rejected_when_the_plan_is_unpaid(): void
    {
        [$customer, $project, $anchor, $language] = $this->makeBilledProject('pending');

        $this->actingAs($customer)
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => $language->id,
            ])
            ->assertForbidden();

        $this->assertSame(1, Service::query()->where('project_id', $project->id)->count());
        $this->assertSame('pending', $anchor->fresh()->status->value ?? $anchor->fresh()->status);
    }

    public function test_failed_included_deploy_can_be_retried_from_the_console(): void
    {
        [$customer, $project, $anchor, $language] = $this->makeBilledProject();

        Bus::fake();

        $this->actingAs($customer)
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => $language->id,
                'frontend' => 'static',
            ]);

        $extra = Service::query()
            ->where('project_id', $project->id)
            ->where('id', '!=', $anchor->id)
            ->first();

        $this->assertNotNull($extra);
        $extra->update(['status' => 'failed']);

        $this->actingAs($customer)
            ->get(route('customer.services.deploying', $extra))
            ->assertOk()
            ->assertSee('Retry deploy')
            ->assertSee('Live console');

        $this->actingAs($customer)
            ->post(route('customer.services.deploying.retry', $extra))
            ->assertRedirect(route('customer.services.deploying', $extra));

        $this->assertSame('provisioning', $extra->fresh()->status->value ?? $extra->fresh()->status);
        Bus::assertDispatched(ProvisionContainerServiceJob::class, 2);
    }

    public function test_other_customers_cannot_deploy_into_a_project(): void
    {
        [, $project, , $language] = $this->makeBilledProject();
        $other = User::factory()->customer()->create();

        $this->actingAs($other)
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => $language->id,
            ])
            ->assertForbidden();
    }

    public function test_unknown_runtime_is_rejected(): void
    {
        [$customer, $project] = $this->makeBilledProject();

        $this->actingAs($customer)
            ->post(route('customer.projects.deploy.store', $project), [
                'language_id' => 999999,
            ])
            ->assertSessionHasErrors('language_id');
    }

    /**
     * @return array{0: User, 1: CustomerProject, 2: Service, 3: ContainerTemplate}
     */
    private function makeBilledProject(string $status = 'active'): array
    {
        $customer = User::factory()->customer()->create();
        $language = ContainerTemplate::factory()->create([
            'slug' => 'static-site',
            'name' => 'Static site',
            'is_active' => true,
            'hosting_type' => 'container',
        ]);
        $product = Product::factory()->containerHosting()->create([
            'name' => 'App Hosting',
            'container_template_id' => $language->id,
            'resource_limits' => ['cpu' => 2, 'memory' => 4096, 'disk' => 40],
            'overage_enabled' => true,
            'cpu_overage_rate' => 10,
        ]);
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'name' => 'LS Production',
        ]);
        $anchor = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => $status,
            'billing_cycle' => 'monthly',
            'custom_price' => 2500,
            'next_due_date' => now()->addMonth(),
        ]);
        $project->update(['billing_service_id' => $anchor->id]);

        return [$customer, $project->fresh(), $anchor->fresh(), $language];
    }
}
