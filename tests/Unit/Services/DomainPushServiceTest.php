<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerWallet;
use App\Models\User;
use App\Services\DomainPushService;
use App\Services\ResellerDomainOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_paid_domain_pushes_when_wallet_has_funds(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 10000,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        $extension = DomainExtension::create([
            'extension' => '.test',
            'enabled' => true,
        ]);
        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'tier' => 'wholesale',
            'period_years' => 1,
            'price' => 500,
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'example',
            'extension' => '.test',
            'status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-CUST-1',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $order = app(ResellerDomainOrderService::class)->createForCustomerCheckout(
            $customer,
            $domain,
            $invoice,
            'example',
            '.test',
            1,
            1000,
        );

        InvoiceItem::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'example.test',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ], app(ResellerDomainOrderService::class)->invoiceItemAttributes($order)));

        app(DomainPushService::class)->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));

        $order->refresh();
        $this->assertSame('pushed', $order->status);
        $this->assertNotNull($order->admin_invoice_id);
        $this->assertSame(9500.0, (float) ResellerWallet::where('reseller_id', $reseller->id)->value('balance'));
    }

    public function test_platform_customer_paid_domain_auto_pushes_for_admin_fulfillment(): void
    {
        $customer = User::factory()->create(['reseller_id' => null]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'platform',
            'extension' => '.test',
            'status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-PLAT-1',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 1200,
            'tax' => 0,
            'total' => 1200,
        ]);

        $order = app(ResellerDomainOrderService::class)->createForCustomerCheckout(
            $customer,
            $domain,
            $invoice,
            'platform',
            '.test',
            1,
            1200,
        );

        $this->assertNotNull($order);
        $this->assertTrue($order->isPlatformOrder());

        InvoiceItem::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => 'platform.test',
            'quantity' => 1,
            'unit_price' => 1200,
            'amount' => 1200,
        ], app(ResellerDomainOrderService::class)->invoiceItemAttributes($order)));

        app(DomainPushService::class)->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));

        $order->refresh();
        $this->assertSame('pushed', $order->status);
        $this->assertNotNull($order->admin_invoice_id);
    }

    public function test_platform_order_failure_does_not_crash_when_reseller_is_null(): void
    {
        $customer = User::factory()->create(['reseller_id' => null, 'phone' => '254700000001']);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'failtest',
            'extension' => '.com',
            'status' => 'pending',
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'domain_name' => 'failtest',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 1500,
            'retail_amount' => 1500,
            'status' => 'pushed',
        ]);

        app(DomainPushService::class)->failOrder(
            $order,
            'You have not signed the latest version of the contract for registering this domain'
        );

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertStringContainsString('contract', $order->failure_reason);
    }

    public function test_complete_order_creates_domain_when_missing_for_platform_registration(): void
    {
        $customer = User::factory()->create(['reseller_id' => null]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_id' => null,
            'domain_name' => 'manualreg',
            'extension' => '.co.ke',
            'years' => 2,
            'wholesale_amount' => 1500,
            'retail_amount' => 1500,
            'status' => 'pushed',
            'pushed_at' => now(),
        ]);

        app(DomainPushService::class)->completeOrder($order, 'Manual registrar');

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->domain_id);

        $domain = Domain::find($order->domain_id);
        $this->assertNotNull($domain);
        $this->assertSame($customer->id, $domain->user_id);
        $this->assertSame('manualreg', $domain->name);
        $this->assertSame('.co.ke', $domain->extension);
        $this->assertSame('active', $domain->status);
        $this->assertSame('Manual registrar', $domain->registrar);
    }

    public function test_customer_paid_domain_queues_when_wallet_is_low(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        ResellerWallet::create([
            'reseller_id' => $reseller->id,
            'balance' => 100,
            'currency' => 'KES',
            'status' => 'active',
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'queued',
            'extension' => '.test',
            'status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'user_id' => $customer->id,
            'invoice_number' => 'INV-CUST-2',
            'status' => 'paid',
            'due_date' => now(),
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'customer_invoice_id' => $invoice->id,
            'domain_name' => 'queued',
            'extension' => '.test',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 500,
            'status' => 'queued',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'description' => 'queued.test',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'custom_options' => ['domain_order_id' => $order->id],
        ]);

        app(DomainPushService::class)->handlePaidDomainInvoice($invoice->fresh(['items', 'user']));

        $this->assertSame('queued', $order->fresh()->status);
        $this->assertSame(100.0, (float) ResellerWallet::where('reseller_id', $reseller->id)->value('balance'));
    }
}
