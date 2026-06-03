<?php

namespace Tests\Feature\Reseller;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\DomainPushService;
use App\Services\NotificationService;
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
}
