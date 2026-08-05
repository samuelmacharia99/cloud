<?php

namespace Tests\Unit\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\ProjectBandwidthBillingService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectBandwidthBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectBandwidthBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(ProjectBandwidthBillingService::class);
    }

    public function test_transfer_bytes_deltas_consecutive_samples_and_handles_counter_reset(): void
    {
        $deployment = ContainerDeployment::factory()->create();

        $this->seedTransferSample($deployment, '2026-03-01 00:00:00', 1_000, 0);
        $this->seedTransferSample($deployment, '2026-03-02 00:00:00', 1_500, 500); // +1000
        $this->seedTransferSample($deployment, '2026-03-03 00:00:00', 100, 50); // reset → +150

        $bytes = ContainerMetric::transferBytesForPeriod(
            $deployment,
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
        );

        $this->assertSame(1150, $bytes);
    }

    public function test_does_not_charge_when_usage_is_within_included_pool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        [$invoice, $service] = $this->makeStandaloneFixture(
            includedGb: 10,
            rate: 50,
        );

        $deployment = $service->containerDeployment;
        // 2 GB transfer (well under 10 GB included)
        $this->seedTransferSample($deployment, '2026-03-01 00:00:00', 0, 0);
        $this->seedTransferSample($deployment, '2026-03-10 00:00:00', 1024 ** 3, 1024 ** 3);

        $this->billing->addBandwidthItemsToInvoice($invoice, $service);

        $this->assertSame(0, $invoice->items()->where('product_type', 'project_bandwidth_overage')->count());
        $this->assertSame(1000.0, (float) $invoice->fresh()->total);
    }

    public function test_charges_overage_for_standalone_container_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        [$invoice, $service] = $this->makeStandaloneFixture(
            includedGb: 1,
            rate: 100,
        );

        $deployment = $service->containerDeployment;
        // 3 GB transfer → 2 GB billable @ 100 = 200
        $gb = 1024 ** 3;
        $this->seedTransferSample($deployment, '2026-03-01 00:00:00', 0, 0);
        $this->seedTransferSample($deployment, '2026-03-10 00:00:00', 2 * $gb, 1 * $gb);

        $this->billing->addBandwidthItemsToInvoice($invoice, $service);

        $item = $invoice->items()->where('product_type', 'project_bandwidth_overage')->first();
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(2.0, (float) $item->quantity, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $item->amount, 0.01);
        $this->assertStringContainsString('Project Bandwidth Overage', $item->description);
    }

    public function test_sums_transfer_across_all_project_deployments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $node = Node::factory()->create();
        $template = ContainerTemplate::factory()->create();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => ['bandwidth_gb' => 1],
            'bandwidth_overage_enabled' => true,
            'bandwidth_overage_rate' => 50,
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
                'project_recipe' => 'laravel_next',
                'project_role' => 'backend',
                'project_billing_anchor' => true,
            ],
        ]);

        $sidecar = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
            'service_meta' => [
                'project_recipe' => 'laravel_next',
                'project_role' => 'frontend',
                'project_billing_anchor' => false,
            ],
        ]);

        $project->update(['billing_service_id' => $anchor->id]);

        $anchorDeployment = ContainerDeployment::factory()->create([
            'service_id' => $anchor->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);
        $sidecarDeployment = ContainerDeployment::factory()->create([
            'service_id' => $sidecar->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $gb = 1024 ** 3;
        // Anchor: 1 GB, sidecar: 1.5 GB → total 2.5 GB → 1.5 billable @ 50 = 75
        $this->seedTransferSample($anchorDeployment, '2026-03-01 00:00:00', 0, 0);
        $this->seedTransferSample($anchorDeployment, '2026-03-10 00:00:00', $gb, 0);
        $this->seedTransferSample($sidecarDeployment, '2026-03-01 00:00:00', 0, 0);
        $this->seedTransferSample($sidecarDeployment, '2026-03-10 00:00:00', $gb, (int) (0.5 * $gb));

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->billing->addBandwidthItemsToInvoice($invoice, $anchor->fresh(['product', 'project', 'containerDeployment']));

        $item = $invoice->items()->where('product_type', 'project_bandwidth_overage')->first();
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(1.5, (float) $item->quantity, 0.01);
        $this->assertEqualsWithDelta(75.0, (float) $item->amount, 0.01);
        $this->assertStringContainsString('2 containers', $item->description);
    }

    public function test_skips_non_billing_anchor_project_services(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        $user = User::factory()->create();
        $node = Node::factory()->create();
        $template = ContainerTemplate::factory()->create();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => ['bandwidth_gb' => 0],
            'bandwidth_overage_enabled' => true,
            'bandwidth_overage_rate' => 100,
        ]);

        $project = CustomerProject::factory()->create(['user_id' => $user->id]);

        $sidecar = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'node_id' => $node->id,
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::parse('2026-04-01'),
            'commenced_at' => Carbon::parse('2026-02-01'),
            'status' => 'active',
            'service_meta' => [
                'project_recipe' => 'laravel_next',
                'project_role' => 'frontend',
                'project_billing_anchor' => false,
            ],
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $sidecar->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $gb = 1024 ** 3;
        $this->seedTransferSample($deployment, '2026-03-01 00:00:00', 0, 0);
        $this->seedTransferSample($deployment, '2026-03-10 00:00:00', 5 * $gb, 0);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $this->billing->addBandwidthItemsToInvoice($invoice, $sidecar);

        $this->assertSame(0, $invoice->items()->where('product_type', 'project_bandwidth_overage')->count());
    }

    /**
     * @return array{0: Invoice, 1: Service}
     */
    private function makeStandaloneFixture(float $includedGb, float $rate): array
    {
        $user = User::factory()->create();
        $node = Node::factory()->create();
        $template = ContainerTemplate::factory()->create();

        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => ['bandwidth_gb' => $includedGb],
            'bandwidth_overage_enabled' => true,
            'bandwidth_overage_rate' => $rate,
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

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        return [$invoice, $service->fresh(['product', 'containerDeployment'])];
    }

    private function seedTransferSample(
        ContainerDeployment $deployment,
        string $recordedAt,
        int $rx,
        int $tx,
    ): void {
        ContainerMetric::create([
            'container_deployment_id' => $deployment->id,
            'sample_type' => ContainerMetric::SAMPLE_USAGE,
            'cpu_percentage' => 10,
            'memory_used_mb' => 128,
            'memory_limit_mb' => 512,
            'memory_percentage' => 25,
            'disk_used_gb' => 1,
            'net_io_rx_bytes' => $rx,
            'net_io_tx_bytes' => $tx,
            'recorded_at' => Carbon::parse($recordedAt),
        ]);
    }
}
