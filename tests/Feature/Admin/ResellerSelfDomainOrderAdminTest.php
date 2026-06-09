<?php

namespace Tests\Feature\Admin;

use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\DomainPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerSelfDomainOrderAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_self_domain_order_appears_on_admin_domain_orders_as_pushed(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_services' => 50,
            'disk_pool_gb' => 50,
            'max_users' => 10,
            'price' => 500,
            'active' => true,
        ]);

        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);

        $admin = User::factory()->admin()->create();

        $extension = DomainExtension::create([
            'extension' => '.test',
            'is_active' => true,
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_name' => 'reseller-brand',
            'extension' => '.test',
            'years' => 1,
            'wholesale_amount' => 800,
            'retail_amount' => 0,
            'status' => 'queued',
            'push_mode' => 'auto',
            'queued_at' => now(),
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'paid_date' => now(),
            'total' => 800,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'description' => 'reseller-brand.test',
            'quantity' => 1,
            'unit_price' => 800,
            'amount' => 800,
            'custom_options' => ['domain_order_id' => $order->id],
        ]);

        app(DomainPushService::class)->ensurePaidInvoiceDomainOrdersPushed($invoice->fresh(['items']));

        $order->refresh();
        $this->assertSame('pushed', $order->status);
        $this->assertNotNull($order->admin_order_id);

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.index'))
            ->assertOk()
            ->assertSee('reseller-brand.test')
            ->assertSee('Reseller (self)')
            ->assertSee('Pushed');
    }

    public function test_ensure_push_retries_when_payment_already_completed(): void
    {
        $reseller = User::factory()->reseller()->create();

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_name' => 'retry-me',
            'extension' => '.ke',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 0,
            'status' => 'queued',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'paid_date' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'description' => 'retry-me.ke',
            'quantity' => 1,
            'unit_price' => 500,
            'amount' => 500,
            'custom_options' => ['domain_order_id' => $order->id],
        ]);

        app(DomainPushService::class)->ensurePaidInvoiceDomainOrdersPushed($invoice);

        $this->assertSame('pushed', $order->fresh()->status);
    }
}
