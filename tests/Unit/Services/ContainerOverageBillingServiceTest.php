<?php

namespace Tests\Unit\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ContainerTemplate;
use App\Models\Invoice;
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
        ]);

        $period = $this->billing->resolveBillingPeriod($service);
        $this->assertNotNull($period);

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'cpu_percentage' => 50,
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
        ]);

        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
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
}
