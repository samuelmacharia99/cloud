<?php

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPlatformRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_revenue_includes_direct_customers_reseller_subscriptions_and_wallet_topups(): void
    {
        $platformCustomer = User::factory()->customer()->create();
        $reseller = User::factory()->reseller()->create();
        $managedCustomer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $platformInvoice = Invoice::factory()->create(['user_id' => $platformCustomer->id]);
        Payment::factory()->create([
            'user_id' => $platformCustomer->id,
            'invoice_id' => $platformInvoice->id,
            'amount' => 1000,
            'status' => PaymentStatus::Completed,
        ]);

        $managedInvoice = Invoice::factory()->create(['user_id' => $managedCustomer->id]);
        Payment::factory()->create([
            'user_id' => $managedCustomer->id,
            'invoice_id' => $managedInvoice->id,
            'amount' => 500,
            'status' => PaymentStatus::Completed,
        ]);

        $subscriptionInvoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
        ]);
        Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $subscriptionInvoice->id,
            'amount' => 2000,
            'status' => PaymentStatus::Completed,
        ]);

        $topupInvoice = Invoice::factory()->create(['user_id' => $reseller->id]);
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
}
