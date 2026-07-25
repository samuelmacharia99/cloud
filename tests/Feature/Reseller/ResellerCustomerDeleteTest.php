<?php

namespace Tests\Feature\Reseller;

use App\Enums\ServiceStatus;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCustomerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
            'package_subscribed_at' => now()->subDay(),
        ]);
    }

    public function test_cannot_delete_customer_with_live_services(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
        ]);

        $response = $this->actingAs($reseller)
            ->delete(route('reseller.customers.destroy', $customer));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $customer->id]);
    }

    public function test_can_delete_customer_when_services_are_terminated(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Terminated,
        ]);

        $response = $this->actingAs($reseller)
            ->delete(route('reseller.customers.destroy', $customer));

        $response->assertRedirect(route('reseller.customers.index'));
        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
    }
}
