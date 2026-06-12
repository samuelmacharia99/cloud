<?php

namespace Tests\Feature\Customer;

use App\Enums\ResellerDomainOrderType;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerWallet;
use App\Models\User;
use App\Services\DomainPushService;
use App\Services\ResellerDomainOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerCustomerDomainTransferOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_transfer_invoice_auto_pushes_when_reseller_wallet_has_funds(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 5000,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        DomainExtension::create([
            'extension' => '.test',
            'enabled' => true,
            'transfer_price' => 800,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'transferme',
            'extension' => '.test',
            'type' => 'transfer',
            'status' => 'pending',
            'transfer_status' => 'pending',
            'epp_code' => 'EPP-CODE-123',
            'old_registrar' => 'Old Registrar',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-TRANSFER-1',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 1200,
            'tax' => 0,
            'total' => 1200,
        ]);

        $order = app(ResellerDomainOrderService::class)->createForTransferCheckout(
            $customer,
            $domain,
            $invoice,
            'transferme',
            '.test',
            1200,
        );

        $this->assertNotNull($order);
        $this->assertTrue($order->isTransfer());

        InvoiceItem::create(array_merge([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'description' => 'Domain Transfer: transferme.test',
            'quantity' => 1,
            'unit_price' => 1200,
            'amount' => 1200,
            'custom_options' => [
                'type' => 'domain_transfer',
                'domain_id' => $domain->id,
            ],
        ], app(ResellerDomainOrderService::class)->invoiceItemAttributes($order)));

        app(DomainPushService::class)->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));

        $order->refresh();
        $domain->refresh();

        $this->assertSame('pushed', $order->status);
        $this->assertNotNull($order->admin_invoice_id);
        $this->assertSame('initiated', $domain->transfer_status);
        $this->assertSame(4200.0, (float) ResellerWallet::where('reseller_id', $reseller->id)->value('balance'));
    }

    public function test_paid_transfer_invoice_queues_when_reseller_wallet_is_low(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 100,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        DomainExtension::create([
            'extension' => '.queue',
            'enabled' => true,
            'transfer_price' => 800,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'queued',
            'extension' => '.queue',
            'type' => 'transfer',
            'status' => 'pending',
            'transfer_status' => 'pending',
            'epp_code' => 'EPP-CODE-456',
            'old_registrar' => 'Old Registrar',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-TRANSFER-2',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 1200,
            'tax' => 0,
            'total' => 1200,
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'customer_invoice_id' => $invoice->id,
            'domain_name' => 'queued',
            'extension' => '.queue',
            'order_type' => ResellerDomainOrderType::Transfer,
            'years' => 1,
            'wholesale_amount' => 800,
            'retail_amount' => 400,
            'status' => 'queued',
            'push_mode' => 'auto',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'domain_id' => $domain->id,
            'description' => 'Domain Transfer: queued.queue',
            'quantity' => 1,
            'unit_price' => 1200,
            'amount' => 1200,
            'custom_options' => [
                'type' => 'domain_transfer',
                'domain_id' => $domain->id,
                'domain_order_id' => $order->id,
            ],
        ]);

        app(DomainPushService::class)->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));

        $this->assertSame('queued', $order->fresh()->status);
        $this->assertSame('pending', $domain->fresh()->transfer_status);
        $this->assertSame(100.0, (float) ResellerWallet::where('reseller_id', $reseller->id)->value('balance'));
    }

    public function test_admin_can_complete_transfer_order(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $admin = User::factory()->admin()->create();

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'complete',
            'extension' => '.test',
            'type' => 'transfer',
            'status' => 'pending',
            'transfer_status' => 'initiated',
            'epp_code' => 'EPP-CODE-789',
            'old_registrar' => 'Old Registrar',
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'domain_name' => 'complete',
            'extension' => '.test',
            'order_type' => ResellerDomainOrderType::Transfer,
            'years' => 1,
            'wholesale_amount' => 800,
            'retail_amount' => 400,
            'status' => 'pushed',
            'pushed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.domain-orders.complete', $order), [
                'registrar' => 'Talksasa Cloud',
            ])
            ->assertRedirect(route('admin.domain-orders.index'))
            ->assertSessionHas('success');

        $order->refresh();
        $domain->refresh();

        $this->assertSame('completed', $order->status);
        $this->assertSame('completed', $domain->transfer_status);
        $this->assertSame('active', $domain->status);
    }
}
