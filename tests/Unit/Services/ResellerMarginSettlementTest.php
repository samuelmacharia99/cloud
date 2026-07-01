<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ResellerMarginEntry;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerMarginSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_margin_recorded_when_managed_customer_pays_via_settlement_service(): void
    {
        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $adminProduct = Product::factory()->create([
            'wholesale_monthly_price' => 300,
            'monthly_price' => 500,
        ]);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => 'Retail Plan',
            'type' => 'shared_hosting',
            'monthly_price' => 800,
            'yearly_price' => 8000,
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 800,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $adminProduct->id,
            'description' => 'Retail Plan — Monthly',
            'quantity' => 1,
            'unit_price' => 800,
            'amount' => 800,
        ]);

        $payment = Payment::create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 800,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $this->assertGreaterThan(0, ResellerMarginEntry::where('reseller_id', $reseller->id)->count());
    }
}
