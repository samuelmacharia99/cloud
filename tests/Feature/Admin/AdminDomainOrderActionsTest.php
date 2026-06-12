<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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

    public function test_index_shows_complete_icon_for_pushed_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $this->createOrder($reseller, 'pushed');

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.index'))
            ->assertOk()
            ->assertSee('Mark as completed (pushed', false);
    }

    public function test_index_shows_push_and_delete_icons_for_queued_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $this->createOrder($reseller, 'queued');

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.index'))
            ->assertOk()
            ->assertSee('Push to admin (queued', false)
            ->assertSee('Delete order record', false);
    }

    public function test_admin_can_complete_queued_order_when_wholesale_invoice_is_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'queued');

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'paid_date' => now(),
            'total' => 500,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'description' => 'example.com',
            'quantity' => 1,
            'unit_price' => 500,
            'amount' => 500,
            'custom_options' => ['domain_order_id' => $order->id],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.complete', $order), [
                'registrar' => 'Namecheap',
            ])
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_admin_push_uses_paid_invoice_without_wallet_debit(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'queued');

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'paid_date' => now(),
            'total' => 500,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'description' => 'example.com',
            'quantity' => 1,
            'unit_price' => 500,
            'amount' => 500,
            'custom_options' => ['domain_order_id' => $order->id],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.push', $order))
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('pushed', $order->status);
        $this->assertNull($order->wallet_transaction_id);
        $this->assertNotNull($order->admin_invoice_id);
    }

    public function test_admin_can_complete_failed_order_after_manual_registrar_work(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'failed');
        $order->update([
            'pushed_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'No API registrar configured for this TLD.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.complete', $order), [
                'registrar' => 'Manual registrar',
            ])
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNull($order->failure_reason);
        $this->assertNull($order->failed_at);
    }

    public function test_admin_can_delete_queued_order(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->createReseller();
        $order = $this->createOrder($reseller, 'queued');

        $this->actingAs($admin)
            ->delete(route('admin.domain-orders.destroy', $order))
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('reseller_domain_orders', ['id' => $order->id]);
    }
}
