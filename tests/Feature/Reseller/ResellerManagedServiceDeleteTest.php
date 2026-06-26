<?php

namespace Tests\Feature\Reseller;

use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerManagedServiceDeleteTest extends TestCase
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

    public function test_reseller_can_delete_managed_customer_service(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $product = Product::create([
            'name' => 'VPS Plan',
            'slug' => 'vps-plan-'.uniqid(),
            'type' => 'vps',
            'monthly_price' => 3000,
            'yearly_price' => 30000,
            'is_active' => true,
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($reseller)
            ->delete(route('reseller.services.destroy', $service))
            ->assertRedirect(route('reseller.customers.show', ['customer' => $customer, 'tab' => 'services']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_foreign_reseller_cannot_delete_managed_service(): void
    {
        $owner = $this->createReseller();
        $other = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $owner->id]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $owner->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($other)
            ->delete(route('reseller.services.destroy', $service))
            ->assertNotFound();

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    public function test_reseller_can_suspend_active_service_from_index_route(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'status' => 'active',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.services.suspend', $service))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('suspended', $service->fresh()->status->value);
    }

    public function test_reseller_cannot_suspend_terminated_service(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'status' => 'terminated',
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.services.suspend', $service))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('terminated', $service->fresh()->status->value);
    }
}
