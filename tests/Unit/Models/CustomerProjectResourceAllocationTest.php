<?php

namespace Tests\Unit\Models;

use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProjectResourceAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocated_resource_shares_sum_service_meta(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => [
                'resource_share' => ['cpu' => 0.55, 'memory' => 0.55],
            ],
        ]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => [
                'resource_share' => ['cpu' => 0.25, 'memory' => 0.25],
            ],
        ]);

        $allocated = $project->fresh()->allocatedResourceShares();

        $this->assertSame(0.8, $allocated['cpu']);
        $this->assertSame(0.8, $allocated['memory']);
    }

    public function test_service_without_share_counts_as_full_plan_footprint(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);

        $anchor = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => ['project_billing_anchor' => true],
        ]);
        $project->update(['billing_service_id' => $anchor->id]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => [],
        ]);

        $allocated = $project->fresh()->allocatedResourceShares();

        $this->assertSame(1.0, $allocated['cpu']);
        $this->assertSame(1.0, $allocated['memory']);
    }

    public function test_billing_anchor_without_share_does_not_consume_allocatable_pool(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);

        $anchor = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => ['project_billing_anchor' => true],
        ]);
        $project->update(['billing_service_id' => $anchor->id]);

        $allocated = $project->fresh()->allocatedResourceShares();
        $share = $project->fresh()->resolveIncludedWorkloadShare();

        $this->assertSame(0.0, $allocated['cpu']);
        $this->assertSame(0.0, $allocated['memory']);
        $this->assertSame(0.25, $share['cpu']);
        $this->assertSame(0.25, $share['memory']);
    }

    public function test_resolve_included_workload_share_uses_remaining_pool(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create([
            'user_id' => $customer->id,
            'resource_pool' => [
                'product_id' => $product->id,
                'cpu_share' => 0.2,
                'memory_share' => 0.2,
            ],
        ]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => [
                'resource_share' => ['cpu' => 0.55, 'memory' => 0.55],
            ],
        ]);

        $share = $project->fresh()->resolveIncludedWorkloadShare();

        $this->assertSame(0.2, $share['cpu']);
        $this->assertSame(0.2, $share['memory']);
    }

    public function test_exhausted_pool_falls_back_to_minimum_workload_share(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $project = CustomerProject::factory()->create(['user_id' => $customer->id]);

        $anchor = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => [
                'project_billing_anchor' => true,
                'resource_share' => ['cpu' => 0.55, 'memory' => 0.55],
            ],
        ]);
        $project->update(['billing_service_id' => $anchor->id]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'project_id' => $project->id,
            'status' => 'active',
            'service_meta' => [
                'resource_share' => ['cpu' => 0.45, 'memory' => 0.45],
            ],
        ]);

        $share = $project->fresh()->resolveIncludedWorkloadShare();

        $this->assertSame(0.05, $share['cpu']);
        $this->assertSame(0.05, $share['memory']);
    }
}
