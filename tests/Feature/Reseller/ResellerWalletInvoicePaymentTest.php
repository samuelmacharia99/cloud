<?php

namespace Tests\Feature\Reseller;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\DomainPushService;
use App\Services\NotificationService;
use App\Services\ResellerInvoicePaymentService;
use App\Services\ResellerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerWalletInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_wallet_payment_marks_invoice_paid_when_side_effects_fail(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $this->mock(DomainPushService::class, function ($mock) {
            $mock->shouldReceive('handlePaidResellerInvoice')
                ->once()
                ->andThrow(new \RuntimeException('Domain push unavailable'));
        });

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyPaymentReceived')->once();
        });

        $reseller = User::factory()->reseller()->create();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 10000]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'subtotal' => 2500,
            'tax' => 0,
            'total' => 2500,
        ]);

        $response = $this->actingAs($reseller)->post(route('reseller.payment.initiate', $invoice), [
            'method' => 'wallet',
            'apply_wallet' => '1',
        ]);

        $response->assertRedirect(route('reseller.invoices.show', $invoice));
        $response->assertSessionHas('success');

        $invoice->refresh();
        $reseller->refresh();

        $this->assertSame('paid', $invoice->status->value);
        $this->assertNotNull($invoice->paid_date);
        $this->assertSame(2500.0, (float) $invoice->wallet_amount_applied);
        $this->assertSame(7500.0, (float) $reseller->wallet->balance);
    }

    public function test_full_wallet_payment_works_for_reseller_subscription_invoice(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $this->mock(DomainPushService::class, function ($mock) {
            $mock->shouldReceive('handlePaidResellerInvoice')->zeroOrMoreTimes();
        });

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyPaymentReceived')->once();
        });

        $reseller = User::factory()->reseller()->create();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 6152]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
            'invoice_number' => 'INV-9YBDTTTWD5',
            'subtotal' => 6148,
            'tax' => 0,
            'total' => 6148,
        ]);

        $response = $this->actingAs($reseller)->post(route('reseller.payment.initiate', $invoice), [
            'method' => 'wallet',
            'apply_wallet' => '1',
        ]);

        $response->assertRedirect(route('reseller.invoices.show', $invoice));
        $response->assertSessionHas('success');

        $invoice->refresh();
        $reseller->refresh();

        $this->assertSame('paid', $invoice->status->value);
        $this->assertSame(6148.0, (float) $invoice->wallet_amount_applied);
        $this->assertSame(4.0, (float) $reseller->wallet->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $reseller->wallet->id,
            'type' => 'subscription_debit',
            'amount' => 6148,
            'reference_id' => $invoice->id,
            'reference_type' => 'Invoice',
            'status' => 'completed',
        ]);
    }

    public function test_partial_wallet_leaves_invoice_unpaid_until_gateway_pays(): void
    {
        Setting::setValue('tax_enabled', 'false');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('paypal_enabled', '0');

        $reseller = User::factory()->reseller()->create();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 500]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'subtotal' => 2000,
            'tax' => 0,
            'total' => 2000,
        ]);

        $this->actingAs($reseller)->post(route('reseller.payment.initiate', $invoice), [
            'method' => 'manual',
            'apply_wallet' => '1',
        ]);

        $invoice->refresh();

        $this->assertSame('unpaid', $invoice->status->value);
        $this->assertSame(500.0, (float) $invoice->wallet_amount_applied);
        $this->assertSame(0.0, (float) $reseller->fresh()->wallet->balance);
    }

    public function test_additional_wallet_apply_debits_incrementally_without_double_counting(): void
    {
        Setting::setValue('tax_enabled', 'false');

        $reseller = User::factory()->reseller()->create();
        $walletService = app(ResellerWalletService::class);
        $walletService->getOrCreate($reseller)->update(['balance' => 500]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'subtotal' => 2000,
            'tax' => 0,
            'total' => 2000,
            'wallet_amount_applied' => 0,
        ]);

        $paymentService = app(ResellerInvoicePaymentService::class);

        $first = $paymentService->applyWallet($invoice, $reseller, true);
        $this->assertSame(500.0, $first['wallet_applied']);
        $this->assertSame(1500.0, $first['amount_due']);
        $this->assertSame(0.0, (float) $reseller->fresh()->wallet->balance);

        $walletService->getOrCreate($reseller)->update(['balance' => 1500]);

        $second = $paymentService->applyWallet($invoice->fresh(), $reseller, true);
        $this->assertSame(2000.0, $second['wallet_applied']);
        $this->assertSame(0.0, $second['amount_due']);
        $this->assertSame(0.0, (float) $reseller->fresh()->wallet->balance);

        $this->assertSame(2, WalletTransaction::query()
            ->where('reference_id', $invoice->id)
            ->where('reference_type', 'Invoice')
            ->where('type', 'domain_debit')
            ->count());
    }
}
