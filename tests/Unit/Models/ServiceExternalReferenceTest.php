<?php

namespace Tests\Unit\Models;

use App\Enums\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceExternalReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_external_reference_reclaims_terminal_duplicate(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::create([
            'name' => 'Hosting',
            'slug' => 'hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'is_active' => true,
        ]);

        $failed = Service::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Old failed hosting',
            'status' => ServiceStatus::Failed,
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'external_reference' => 'devkiste',
        ]);

        $active = Service::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Active hosting',
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'service_meta' => ['username' => 'devkiste'],
        ]);

        $resolved = Service::resolveExternalReferenceForAssignment('devkiste', $active->id);

        $this->assertSame('devkiste', $resolved);
        $this->assertNull($failed->fresh()->external_reference);
    }

    public function test_resolve_external_reference_blocks_active_duplicate(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::create([
            'name' => 'Hosting',
            'slug' => 'hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'is_active' => true,
        ]);

        Service::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Existing hosting',
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'external_reference' => 'devkiste',
        ]);

        $other = Service::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Other hosting',
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already linked');

        Service::resolveExternalReferenceForAssignment('devkiste', $other->id);
    }
}
