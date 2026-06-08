<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDomainOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter '.uniqid(),
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
        ]);
    }

    private function createOrder(User $reseller, string $status, ?Domain $domain = null): ResellerDomainOrder
    {
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        return ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_id' => $domain?->id,
            'domain_name' => 'example',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 200,
            'status' => $status,
            'push_mode' => 'auto',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);
    }

    public function test_reseller_can_cancel_queued_order_and_removes_pending_domain(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'pending',
            'type' => 'registration',
        ]);

        $order = $this->createOrder($reseller, 'queued', $domain);

        $this->actingAs($reseller)
            ->post(route('reseller.domain-orders.cancel', $order))
            ->assertRedirect(route('reseller.domain-orders.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reseller_domain_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
        $this->assertNotNull($order->fresh()->cancelled_at);
        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_reseller_can_delete_cancelled_order(): void
    {
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'cancelled');

        $this->actingAs($reseller)
            ->delete(route('reseller.domain-orders.destroy', $order))
            ->assertRedirect(route('reseller.domain-orders.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('reseller_domain_orders', ['id' => $order->id]);
    }

    public function test_reseller_can_delete_failed_order(): void
    {
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'failed');

        $this->actingAs($reseller)
            ->delete(route('reseller.domain-orders.destroy', $order))
            ->assertRedirect(route('reseller.domain-orders.index'));

        $this->assertDatabaseMissing('reseller_domain_orders', ['id' => $order->id]);
    }

    public function test_reseller_cannot_cancel_pushed_order(): void
    {
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'pushed');

        $this->actingAs($reseller)
            ->post(route('reseller.domain-orders.cancel', $order))
            ->assertRedirect(route('reseller.domain-orders.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('reseller_domain_orders', [
            'id' => $order->id,
            'status' => 'pushed',
        ]);
    }

    public function test_reseller_cannot_delete_queued_or_pushed_order(): void
    {
        $reseller = $this->createReseller();
        $queued = $this->createOrder($reseller, 'queued');
        $pushed = $this->createOrder($reseller, 'pushed');

        $this->actingAs($reseller)
            ->delete(route('reseller.domain-orders.destroy', $queued))
            ->assertRedirect(route('reseller.domain-orders.index'))
            ->assertSessionHas('error');

        $this->actingAs($reseller)
            ->delete(route('reseller.domain-orders.destroy', $pushed))
            ->assertRedirect(route('reseller.domain-orders.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('reseller_domain_orders', ['id' => $queued->id]);
        $this->assertDatabaseHas('reseller_domain_orders', ['id' => $pushed->id]);
    }

    public function test_reseller_cannot_manage_another_resellers_order(): void
    {
        $reseller = $this->createReseller();
        $otherReseller = $this->createReseller();
        $order = $this->createOrder($otherReseller, 'queued');

        $this->actingAs($reseller)
            ->post(route('reseller.domain-orders.cancel', $order))
            ->assertForbidden();

        $this->actingAs($reseller)
            ->delete(route('reseller.domain-orders.destroy', $order))
            ->assertForbidden();
    }
}
