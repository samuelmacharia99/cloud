<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_pending_unpaid_order_from_index(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create([
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.orders.destroy', $order), [
                'status' => 'pending',
            ])
            ->assertRedirect(route('admin.orders.index', ['status' => 'pending']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    public function test_admin_cannot_delete_paid_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = Order::factory()->create([
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.orders.destroy', $order))
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_non_admin_cannot_delete_order(): void
    {
        $customer = User::factory()->customer()->create();
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($customer)
            ->delete(route('admin.orders.destroy', $order))
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }
}
