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

    public function test_mpesa_available_for_kes_invoice_without_kenya_country(): void
    {
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('mpesa_enabled', '1');
        Setting::setValue('mpesa_consumer_key', 'test-key');
        Setting::setValue('mpesa_consumer_secret', 'test-secret');
        Setting::setValue('mpesa_shortcode', '123456');
        Setting::setValue('mpesa_passkey', 'test-passkey');

        $customer = User::factory()->customer()->create(['country' => null]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'currency' => 'KES',
            'status' => 'unpaid',
        ]);

        $gateways = PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice);

        $this->assertArrayHasKey('mpesa', $gateways);
    }

    public function test_mpesa_hidden_for_non_kes_invoice(): void
    {
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('mpesa_enabled', '1');
        Setting::setValue('mpesa_consumer_key', 'test-key');
        Setting::setValue('mpesa_consumer_secret', 'test-secret');
        Setting::setValue('mpesa_shortcode', '123456');
        Setting::setValue('mpesa_passkey', 'test-passkey');

        $customer = User::factory()->customer()->create(['country' => 'KE']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'currency' => 'USD',
            'status' => 'unpaid',
        ]);

        $gateways = PaymentGatewayFactory::getAvailableGatewaysForInvoice($invoice);

        $this->assertArrayNotHasKey('mpesa', $gateways);
    }

    public function test_stripe_available_with_legacy_card_enabled_setting(): void
    {
        Setting::setValue('manual_enabled', '0');
        Setting::setValue('bank_transfer_enabled', '0');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('paypal_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('card_enabled', 'true');
        Setting::setValue('stripe_key', 'sk_test_legacy');
        Setting::setValue('stripe_publishable_key', 'pk_test_legacy');

        $gateways = PaymentGatewayFactory::getAvailableGateways();

        $this->assertArrayHasKey('stripe', $gateways);
    }

    public function test_wallet_topup_excludes_manual_and_bank_transfer(): void
    {
        Setting::setValue('manual_enabled', '1');
        Setting::setValue('bank_transfer_enabled', '1');
        Setting::setValue('bank_name', 'Test Bank');
        Setting::setValue('bank_account_number', '123');
        Setting::setValue('mpesa_enabled', '0');
        Setting::setValue('stripe_enabled', '0');
        Setting::setValue('paypal_enabled', '0');

        $customer = User::factory()->customer()->create();

        $gateways = PaymentGatewayFactory::getAvailableGatewaysForUser($customer);

        $this->assertArrayNotHasKey('manual', $gateways);
        $this->assertArrayNotHasKey('bank_transfer', $gateways);
    }
}
