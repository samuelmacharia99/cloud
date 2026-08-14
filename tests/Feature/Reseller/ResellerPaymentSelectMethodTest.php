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

class ResellerPaymentSelectMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_pay_page_loads_when_stripe_enabled_in_settings(): void
    {
        Setting::setValue('stripe_enabled', '1');
        Setting::setValue('stripe_secret_key', 'sk_test_example');
        Setting::setValue('stripe_publishable_key', 'pk_test_example');
        Setting::setValue('mpesa_enabled', '0');

        $reseller = User::factory()->reseller()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 1500,
        ]);

        $response = $this->actingAs($reseller)
            ->get(route('reseller.payment.select-method', $invoice));

        $response->assertOk();
        $response->assertSee('Select Payment Method');
    }

    public function test_pay_page_json_includes_wallet_fields_when_no_gateways(): void
    {
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');

        $reseller = User::factory()->reseller()->create();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 5000]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 1500,
            'currency' => 'KES',
        ]);

        $response = $this->actingAs($reseller)
            ->getJson(route('reseller.payment.select-method', $invoice));

        $response->assertOk();
        $response->assertJsonPath('wallet_balance', 5000);
        $response->assertJsonPath('amount_due', 1500);
        $response->assertJsonPath('can_pay_with_wallet', true);
        $response->assertJsonPath('gateways', []);
    }

    public function test_pay_page_offers_wallet_option_when_balance_covers_invoice(): void
    {
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');

        $reseller = User::factory()->reseller()->create();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 5000]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 1500,
            'currency' => 'KES',
        ]);

        $response = $this->actingAs($reseller)
            ->get(route('reseller.payment.select-method', $invoice));

        $response->assertOk();
        $response->assertSee('Pay with wallet balance');
        $response->assertSee('Continue to Payment');
        $response->assertDontSee('No payment methods are currently available');
    }

    public function test_full_wallet_payment_works_when_no_gateways_configured(): void
    {
        Setting::setValue('tax_enabled', 'false');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');

        $this->mock(DomainPushService::class, function ($mock) {
            $mock->shouldReceive('handlePaidResellerInvoice')->zeroOrMoreTimes();
            $mock->shouldReceive('ensurePaidInvoiceDomainOrdersPushed')->zeroOrMoreTimes();
        });
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyPaymentReceived')->once();
        });

        $reseller = User::factory()->reseller()->create();
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 9000]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'subtotal' => 2500,
            'tax' => 0,
            'total' => 2500,
            'currency' => 'KES',
        ]);

        $response = $this->actingAs($reseller)->post(route('reseller.payment.initiate', $invoice), [
            'method' => 'wallet',
            'apply_wallet' => '1',
        ]);

        $response->assertRedirect(route('reseller.invoices.show', $invoice));
        $response->assertSessionHas('success');
        $this->assertSame('paid', $invoice->fresh()->status->value);
        $this->assertSame(6500.0, (float) $reseller->fresh()->wallet->balance);
    }
}
