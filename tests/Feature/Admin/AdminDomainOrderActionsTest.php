<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDomainOrderActionsTest extends TestCase
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
            'pushed_at' => $status === 'pushed' ? now() : null,
            'expires_at' => now()->addDays(10),
        ]);
    }

    public function test_admin_can_complete_pushed_order_from_index(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'pushed');

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.complete', $order), [
                'registrar' => 'Namecheap',
            ])
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reseller_domain_orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);
    }

    public function test_admin_can_cancel_queued_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'queued');

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.cancel', $order))
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('reseller_domain_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_admin_can_delete_cancelled_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'cancelled');

        $this->actingAs($admin)
            ->delete(route('admin.domain-orders.destroy', $order))
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('reseller_domain_orders', ['id' => $order->id]);
    }

    public function test_admin_cannot_cancel_pushed_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'pushed');

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.cancel', $order))
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('reseller_domain_orders', [
            'id' => $order->id,
            'status' => 'pushed',
        ]);
    }

    public function test_index_shows_action_buttons_for_pushed_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $this->createOrder($reseller, 'pushed');

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.index'))
            ->assertOk()
            ->assertSee('Complete')
            ->assertSee('Fail');
    }
}
