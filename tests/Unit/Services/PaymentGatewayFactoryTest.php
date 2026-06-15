<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGatewayFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_payment_hidden_when_disabled_in_settings(): void
    {
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');

        $customer = User::factory()->customer()->create(['country' => 'Kenya']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'currency' => 'KES',
            'status' => 'unpaid',
        ]);

        $gateways = PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice);

        $this->assertArrayNotHasKey('manual', $gateways);
    }

    public function test_manual_payment_shown_when_enabled_in_settings(): void
    {
        Setting::setValue('manual_enabled', '1');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');

        $customer = User::factory()->customer()->create(['country' => 'Kenya']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'currency' => 'KES',
            'status' => 'unpaid',
        ]);

        $gateways = PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice);

        $this->assertArrayHasKey('manual', $gateways);
    }
}
