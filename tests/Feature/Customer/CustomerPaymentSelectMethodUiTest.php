<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPaymentSelectMethodUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('stripe_enabled', '1');
        Setting::setValue('stripe_secret_key', 'sk_test_example');
        Setting::setValue('stripe_publishable_key', 'pk_test_example');
        Setting::setValue('mpesa_enabled', '1');
        Setting::setValue('mpesa_consumer_key', 'test_key');
        Setting::setValue('mpesa_consumer_secret', 'test_secret');
        Setting::setValue('mpesa_shortcode', '174379');
        Setting::setValue('mpesa_passkey', 'test_passkey');
    }

    public function test_pay_page_shows_checkout_steps_terms_links_and_shared_payment_chooser(): void
    {
        $customer = User::factory()->customer()->create([
            'phone' => '0712345678',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 1500,
            'subtotal' => 1500,
            'tax' => 0,
            'currency' => 'KES',
        ]);

        $response = $this->actingAs($customer)
            ->get(route('customer.payment.select-method', $invoice));

        $response->assertOk()
            ->assertSee('Pay invoice', false)
            ->assertSee('Checkout progress', false)
            ->assertSee('Terms of Service', false)
            ->assertSee('Privacy Policy', false)
            ->assertSee(route('terms'), false)
            ->assertSee(route('privacy'), false)
            ->assertSee('Send M-Pesa prompt', false)
            ->assertSee('M-PESA', false)
            ->assertSee('Recommended', false)
            ->assertSee('0712345678', false)
            ->assertSee('Amount due', false);
    }

    public function test_cart_checkout_review_shows_steps_and_linked_terms(): void
    {
        $customer = User::factory()->customer()->create();

        $product = Product::factory()->create([
            'monthly_price' => 500,
            'is_active' => true,
            'visible_to_resellers' => false,
        ]);

        session([CheckoutController::CART_SESSION_KEY => [
            'item-1' => [
                'type' => 'product',
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
            ],
        ]]);

        $response = $this->actingAs($customer)
            ->get(route('customer.checkout.show'));

        $response->assertOk()
            ->assertSee('Checkout progress', false)
            ->assertSee('Terms of Service', false)
            ->assertSee('Privacy Policy', false)
            ->assertSee('Place order &amp; continue to pay', false);
    }
}
