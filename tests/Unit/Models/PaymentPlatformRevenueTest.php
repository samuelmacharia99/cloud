<?php

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPlatformRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_revenue_includes_direct_customers_reseller_subscriptions_and_wallet_topups(): void
    {
        $platformCustomer = User::factory()->customer()->create(['preferred_currency' => 'KES']);
        $reseller = User::factory()->reseller()->create(['preferred_currency' => 'KES']);
        $managedCustomer = User::factory()->customer()->create([
            'reseller_id' => $reseller->id,
            'preferred_currency' => 'KES',
        ]);

        $platformInvoice = Invoice::factory()->create([
            'user_id' => $platformCustomer->id,
            'status' => 'paid',
            'currency' => 'KES',
        ]);
        Payment::factory()->create([
            'user_id' => $platformCustomer->id,
            'invoice_id' => $platformInvoice->id,
            'amount' => 1000,
            'status' => PaymentStatus::Completed,
        ]);

        $managedInvoice = Invoice::factory()->create([
            'user_id' => $managedCustomer->id,
            'status' => 'paid',
            'currency' => 'KES',
        ]);
        Payment::factory()->create([
            'user_id' => $managedCustomer->id,
            'invoice_id' => $managedInvoice->id,
            'amount' => 500,
            'status' => PaymentStatus::Completed,
        ]);

        $subscriptionInvoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'paid',
            'currency' => 'KES',
        ]);
        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $subscriptionInvoice->id,
            'amount' => 2000,
            'status' => PaymentStatus::Completed,
        ]);

        $topupInvoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'currency' => 'KES',
        ]);
        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $topupInvoice->id,
            'amount' => 300,
            'payment_purpose' => 'wallet_topup',
            'status' => PaymentStatus::Completed,
        ]);

        $total = (float) Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');

        $this->assertSame(3300.0, $total);
    }

    public function test_platform_revenue_includes_reseller_domain_wholesale_payments(): void
    {
        $reseller = User::factory()->reseller()->create(['preferred_currency' => 'KES']);
        $domainInvoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => null,
            'status' => 'paid',
            'currency' => 'KES',
            'notes' => 'Domain registration order',
        ]);
        InvoiceItem::create([
            'invoice_id' => $domainInvoice->id,
            'product_type' => 'Domain',
            'description' => 'example.com (1 year)',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
        ]);
        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $domainInvoice->id,
            'amount' => 1500,
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $pushInvoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'invoice_number' => 'PUSH-TEST123',
            'status' => 'paid',
            'currency' => 'KES',
        ]);
        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $pushInvoice->id,
            'amount' => 1500,
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        $total = (float) Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');

        $this->assertSame(1500.0, $total);
    }
}
