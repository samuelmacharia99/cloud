<?php

namespace Tests\Unit\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ContainerOverageBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContainerOverageBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContainerOverageBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(ContainerOverageBillingService::class);
    }

    public function test_product_resource_limits_are_used_for_included_usage(): void
    {
        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 4.0,
            'required_ram_mb' => 4096,
            'required_storage_gb' => 40,
        ]);

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => 1,
                'memory' => 512,
                'disk' => 10,
            ],
            'overage_enabled' => true,
            'cpu_overage_rate' => 10,
            'ram_overage_rate' => 5,
            'disk_overage_rate' => 3,
        ]);

        $limits = $product->getIncludedContainerLimits($template);

        $this->assertSame(1.0, $limits['cpu']);
        $this->assertSame(512, $limits['memory_mb']);
        $this->assertSame(10.0, $limits['disk_gb']);
    }

    public function test_template_defaults_are_used_when_product_limits_are_missing(): void
    {
        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 0.5,
            'required_ram_mb' => 256,
            'required_storage_gb' => 2,
        ]);

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => null,
        ]);

        $limits = $product->getIncludedContainerLimits($template);

        $this->assertSame(0.5, $limits['cpu']);
        $this->assertSame(256, $limits['memory_mb']);
        $this->assertSame(2.0, $limits['disk_gb']);
    }

    public function test_next_invoice_resumes_from_previous_metering_snapshot_without_a_gap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-20 12:00:00'));
        $user = User::factory()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-01-01'),
            'billing_cycle' => 'monthly',
        ]);
        $previousInvoice = Invoice::factory()->create(['user_id' => $user->id]);
        InvoiceItem::create([
            'invoice_id' => $previousInvoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'product_type' => 'container_ram_overage',
            'description' => 'RAM Overage — previous snapshot',
            'quantity' => 1,
            'unit_price' => 1,
            'amount' => 1,
            'custom_options' => [
                'metered_billing_from' => '2026-02-01T00:00:00+00:00',
                'metered_billing_to' => '2026-02-20T00:00:00+00:00',
            ],
        ]);
        $nextInvoice = Invoice::factory()->create(['user_id' => $user->id]);

        $period = $this->billing->resolveBillingPeriod($service, $nextInvoice);

        $this->assertNotNull($period);
        $this->assertSame('2026-02-20 00:00:00', $period['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-20 12:00:00', $period['to']->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_adds_cpu_and_ram_overage_items_to_invoice_when_usage_exceeds_limits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $node = Node::factory()->create(['cpu_cores' => 4]);

        $template = ContainerTemplate::factory()->create([
            'required_cpu_cores' => 4.0,
            'required_ram_mb' => 4096,
        ]);

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => [
                'cpu' => 1,
                'memory' => 512,
                'disk' => 10,
            ],
            'overage_enabled' => true,
            'cpu_overage_rate' => 10,
            'ram_overage_rate' => 5,
            'disk_overage_rate' => 3,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'cpu_limit' => 4.0,
        ]);

        $period = $this->billing->resolveBillingPeriod($service);
        $this->assertNotNull($period);

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            // Docker CPU% is absolute: 250% means an average of 2.5 cores.
            'cpu_percentage' => 250,
            'memory_used_mb' => 1024,
            'memory_limit_mb' => 2048,
            'memory_percentage' => 50,
            'disk_used_gb' => 15,
            'recorded_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->billing->addOverageItemsToInvoice($invoice, $service);

        $invoice->refresh();
        $items = $invoice->items()->pluck('description')->all();

        $this->assertCount(3, $items);
        $this->assertTrue(collect($items)->contains(fn (string $line) => str_contains($line, 'CPU Overage')));
        $this->assertTrue(collect($items)->contains(fn (string $line) => str_contains($line, 'RAM Overage')));
        $this->assertTrue(collect($items)->contains(fn (string $line) => str_contains($line, 'Disk Overage')));
        $this->assertGreaterThan(1000, (float) $invoice->total);

        $firstTotal = (float) $invoice->total;
        $this->billing->addOverageItemsToInvoice($invoice, $service);
        $invoice->refresh();
        $this->assertCount(3, $invoice->items);
        $this->assertSame($firstTotal, (float) $invoice->total);

        Carbon::setTestNow();
    }

    public function test_does_not_add_overage_items_when_usage_is_within_limits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $node = Node::factory()->create(['cpu_cores' => 4]);

        $product = Product::factory()->containerHosting()->create([
            'resource_limits' => [
                'cpu' => 2,
                'memory' => 2048,
                'disk' => 10,
            ],
            'overage_enabled' => true,
            'cpu_overage_rate' => 10,
            'ram_overage_rate' => 5,
            'disk_overage_rate' => 3,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'cpu_limit' => 4.0,
        ]);

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 25,
            'memory_used_mb' => 256,
            'memory_limit_mb' => 2048,
            'memory_percentage' => 12.5,
            'disk_used_gb' => 5,
            'recorded_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->billing->addOverageItemsToInvoice($invoice, $service);

        $invoice->refresh();

        $this->assertCount(0, $invoice->items);
        $this->assertSame('1000.00', $invoice->total);

        Carbon::setTestNow();
    }

    public function test_ram_overage_uses_time_weighted_samples_not_peak_times_full_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $node = Node::factory()->create(['cpu_cores' => 4]);

        $product = Product::factory()->containerHosting()->create([
            'resource_limits' => [
                'cpu' => 2,
                'memory' => 512,
                'disk' => 10,
            ],
            'overage_enabled' => true,
            'cpu_overage_rate' => 0,
            'ram_overage_rate' => 5,
            'disk_overage_rate' => 0,
        ]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'cpu_limit' => 1.0,
            'memory_limit_mb' => 512,
        ]);

        // Within included limit for most of the window.
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 10,
            'memory_used_mb' => 256,
            'memory_limit_mb' => 512,
            'memory_percentage' => 50,
            'disk_used_gb' => 2,
            'recorded_at' => Carbon::parse('2026-03-10 10:00:00'),
        ]);

        // Brief spike to 2 GB, then we end the sample window an hour later.
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 10,
            'memory_used_mb' => 2048,
            'memory_limit_mb' => 2048,
            'memory_percentage' => 100,
            'disk_used_gb' => 2,
            'recorded_at' => Carbon::parse('2026-03-14 11:00:00'),
        ]);

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 10,
            'memory_used_mb' => 256,
            'memory_limit_mb' => 512,
            'memory_percentage' => 50,
            'disk_used_gb' => 2,
            'recorded_at' => Carbon::parse('2026-03-14 12:00:00'),
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->billing->addOverageItemsToInvoice($invoice, $service);

        $item = $invoice->items()->where('description', 'like', 'RAM Overage%')->first();
        $this->assertNotNull($item);

        // Excess for the 1-hour spike: (2 - 0.5) GB × 1 hour = 1.5 GB-hours → KES 7.50
        // Must not use peak × full period (~1.5 × hundreds of hours).
        $this->assertEqualsWithDelta(1.5, (float) $item->quantity, 0.01);
        $this->assertEqualsWithDelta(7.5, (float) $item->amount, 0.01);
        $this->assertStringContainsString('avg usage:', $item->description);
        $this->assertStringContainsString('peak: 2 GB', $item->description);

        Carbon::setTestNow();
    }

    public function test_pools_cpu_overage_across_project_containers_against_package_specs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $node = Node::factory()->create(['cpu_cores' => 8]);
        $product = Product::factory()->containerHosting()->create([
            'resource_limits' => [
                'cpu' => 1,
                'memory' => 2048,
                'disk' => 40,
            ],
            'overage_enabled' => true,
            'cpu_overage_rate' => 10,
            'ram_overage_rate' => 0,
            'disk_overage_rate' => 0,
        ]);
        $project = CustomerProject::factory()->create(['user_id' => $user->id]);

        $anchor = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
            'service_meta' => [
                'project_recipe' => 'da_convert',
                'project_role' => 'primary',
                'project_billing_anchor' => true,
            ],
        ]);
        $sibling = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
            'service_meta' => [
                'project_recipe' => 'da_convert',
                'project_role' => 'site',
                'project_billing_anchor' => false,
            ],
        ]);
        $project->update(['billing_service_id' => $anchor->id]);

        $anchorDeployment = ContainerDeployment::factory()->create([
            'service_id' => $anchor->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $siblingDeployment = ContainerDeployment::factory()->create([
            'service_id' => $sibling->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        foreach ([$anchorDeployment, $siblingDeployment] as $deployment) {
            ContainerMetric::create([
                'container_deployment_id' => $deployment->id,
                'sample_type' => ContainerMetric::SAMPLE_USAGE,
                'cpu_percentage' => 80,
                'memory_used_mb' => 256,
                'memory_limit_mb' => 1024,
                'memory_percentage' => 25,
                'disk_used_gb' => 2,
                'recorded_at' => Carbon::parse('2026-03-10 10:00:00'),
            ]);
        }

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->billing->addOverageItemsToInvoice($invoice, $sibling);
        $this->assertCount(0, $invoice->fresh()->items);

        $this->billing->addOverageItemsToInvoice($invoice, $anchor->fresh(['product', 'project', 'containerDeployment']));
        $item = $invoice->items()->where('product_type', 'container_cpu_overage')->first();
        $this->assertNotNull($item);
        $this->assertStringContainsString('package included', $item->description);

        Carbon::setTestNow();
    }
}
