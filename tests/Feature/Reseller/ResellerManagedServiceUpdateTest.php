<?php

namespace Tests\Feature\Reseller;

use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerManagedServiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 10,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now(),
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_reseller_can_update_managed_customer_service_billing_fields(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $product = Product::create([
            'name' => 'Bronze Hosting',
            'slug' => 'bronze-hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'is_active' => true,
        ]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'name' => 'Bronze',
            'type' => 'shared_hosting',
            'monthly_price' => 800,
            'yearly_price' => 8000,
            'is_active' => true,
        ]);

        $service = Service::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'name' => 'Bronze Hosting',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'service_meta' => ['reseller_product_id' => $listing->id],
        ]);

        $response = $this->actingAs($reseller)->patch(route('reseller.services.update', $service), [
            'reseller_product_id' => $listing->id,
            'billing_cycle' => 'annual',
            'custom_price' => 7500,
            'next_due_date' => now()->addYear()->format('Y-m-d'),
            'commenced_at' => now()->subMonth()->format('Y-m-d'),
            'return_to' => 'customer',
        ]);

        $response->assertRedirect(route('reseller.customers.show', $customer));
        $response->assertSessionHas('success');

        $service->refresh();
        $this->assertSame('annual', $service->billing_cycle);
        $this->assertEquals(7500, (float) $service->custom_price);
        $this->assertSame(now()->addYear()->format('Y-m-d'), $service->next_due_date->format('Y-m-d'));
    }

    public function test_foreign_reseller_cannot_update_managed_service(): void
    {
        $reseller = $this->createReseller();
        $otherReseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $product = Product::create([
            'name' => 'Test Hosting',
            'slug' => 'test-hosting-'.uniqid(),
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'is_active' => true,
        ]);

        $listing = ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'name' => 'Test',
            'type' => 'shared_hosting',
            'monthly_price' => 800,
            'yearly_price' => 8000,
            'is_active' => true,
        ]);

        $service = Service::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'name' => 'Test Hosting',
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
        ]);

        $this->actingAs($otherReseller)->patch(route('reseller.services.update', $service), [
            'reseller_product_id' => $listing->id,
            'billing_cycle' => 'annual',
            'next_due_date' => now()->addYear()->format('Y-m-d'),
        ])->assertNotFound();
    }
}
