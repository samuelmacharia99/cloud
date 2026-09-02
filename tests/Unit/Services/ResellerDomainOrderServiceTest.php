<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\User;
use App\Services\ResellerDomainOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDomainOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_orders_for_invoice_backfills_missing_platform_domain_order(): void
    {
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'missingorder',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'total' => 1500,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'product_type' => 'Domain',
            'description' => 'Domain registration: missingorder.co.ke (1 year(s))',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
            'custom_options' => [
                'type' => 'domain_registration',
                'domain_id' => $domain->id,
            ],
        ]);

        $created = app(ResellerDomainOrderService::class)->ensureOrdersForInvoice($invoice);

        $this->assertSame(1, $created);

        $invoice->refresh()->load('items');
        $orderId = $invoice->items->first()->custom_options['domain_order_id'] ?? null;

        $this->assertNotNull($orderId);

        $order = ResellerDomainOrder::find($orderId);
        $this->assertNotNull($order);
        $this->assertTrue($order->isPlatformOrder());
        $this->assertSame('missingorder', $order->domain_name);
    }

    public function test_ensure_orders_skips_renewal_invoice_lines(): void
    {
        $reseller = User::factory()->reseller()->create();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'renewskip',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'total' => 1000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'product_type' => 'Domain',
            'description' => 'Renew renewskip.com (1 year)',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'custom_options' => [
                'type' => 'domain_renewal',
                'renewal_order_id' => 99,
            ],
        ]);

        $created = app(ResellerDomainOrderService::class)->ensureOrdersForInvoice($invoice);

        $this->assertSame(0, $created);
        $this->assertDatabaseCount('reseller_domain_orders', 0);
    }

    public function test_retracts_registration_orders_created_from_renewal_lines(): void
    {
        $reseller = User::factory()->reseller()->create();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'dualqueue',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'total' => 900,
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_id' => $domain->id,
            'customer_invoice_id' => $invoice->id,
            'domain_name' => 'dualqueue',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 900,
            'retail_amount' => 0,
            'status' => 'queued',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'product_type' => 'Domain',
            'description' => 'Renew dualqueue.com (1 year)',
            'quantity' => 1,
            'unit_price' => 900,
            'amount' => 900,
            'custom_options' => [
                'type' => 'domain_renewal',
                'renewal_order_id' => 12,
                'domain_order_id' => $order->id,
            ],
        ]);

        $retracted = app(ResellerDomainOrderService::class)
            ->retractMisclassifiedRenewalRegistrationOrders($invoice);

        $this->assertSame(1, $retracted);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertArrayNotHasKey('domain_order_id', $invoice->fresh()->items->first()->custom_options ?? []);
    }

    public function test_registration_queue_excludes_renewal_backfill_orders(): void
    {
        $reseller = User::factory()->reseller()->create();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'keepseparate',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'total' => 900,
        ]);

        $backfill = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_id' => $domain->id,
            'customer_invoice_id' => $invoice->id,
            'domain_name' => 'keepseparate',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 900,
            'retail_amount' => 0,
            'status' => 'queued',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'domain_id' => $domain->id,
            'product_type' => 'Domain',
            'description' => 'Renew keepseparate.com (1 year)',
            'quantity' => 1,
            'unit_price' => 900,
            'amount' => 900,
            'custom_options' => [
                'type' => 'domain_renewal',
                'renewal_order_id' => 44,
                'domain_order_id' => $backfill->id,
            ],
        ]);

        $registration = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_name' => 'newreg',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 1200,
            'retail_amount' => 0,
            'status' => 'queued',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $visible = ResellerDomainOrder::query()->forRegistrationQueue()->pluck('id');

        $this->assertTrue($visible->contains($registration->id));
        $this->assertFalse($visible->contains($backfill->id));
    }
}
