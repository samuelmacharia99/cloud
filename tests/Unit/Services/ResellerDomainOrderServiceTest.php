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
}
