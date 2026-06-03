<?php

namespace Tests\Feature\Reseller;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
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

    public function test_pay_page_json_includes_gateways_when_stripe_enabled(): void
    {
        Setting::setValue('stripe_enabled', '1');
        Setting::setValue('stripe_secret_key', 'sk_test_example');
        Setting::setValue('stripe_publishable_key', 'pk_test_example');

        $reseller = User::factory()->reseller()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
        ]);

        $response = $this->actingAs($reseller)
            ->getJson(route('reseller.payment.select-method', $invoice));

        $response->assertOk();
        $response->assertJsonStructure(['gateways', 'wallet_balance', 'amount_due']);
    }
}
