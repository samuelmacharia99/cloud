<?php

namespace Tests\Feature\Reseller;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\DomainPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerMpesaPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('mpesa_enabled', '1');
        Setting::setValue('mpesa_environment', 'sandbox');
        Setting::setValue('mpesa_shortcode', '174379');
        Setting::setValue('mpesa_consumer_key', 'platform-key');
        Setting::setValue('mpesa_consumer_secret', 'platform-secret');
        Setting::setValue('mpesa_passkey', 'platform-passkey');
        Setting::setValue('site_url', 'https://example.test');
        Setting::setValue('tax_enabled', 'false');
    }

    public function test_mpesa_status_poll_uses_platform_credentials_for_reseller_wholesale_invoice(): void
    {
        Cache::flush();

        Http::fake([
            '*/oauth/v1/generate*' => function ($request) {
                $auth = (string) $request->header('Authorization')[0];
                $decoded = base64_decode(str_replace('Basic ', '', $auth));

                if ($decoded !== 'platform-key:platform-secret') {
                    return Http::response(['errorMessage' => 'Invalid credentials'], 401);
                }

                return Http::response(['access_token' => 'platform-token'], 200);
            },
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ], 200),
        ]);

        $this->mock(DomainPushService::class, function ($mock) {
            $mock->shouldReceive('handlePaidResellerInvoice')->once();
        });

        $reseller = User::factory()->reseller()->create([
            'settings' => [
                'mpesa' => [
                    'business_shortcode' => '999888',
                    'consumer_key' => 'reseller-key',
                    'consumer_secret' => 'reseller-secret',
                    'passkey' => 'reseller-passkey',
                ],
            ],
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'subtotal' => 1500,
            'tax' => 0,
            'total' => 1500,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'mpesa',
            'status' => PaymentStatus::Pending,
            'amount' => 1500,
            'transaction_reference' => 'ws_CO_reseller_domain',
        ]);

        $response = $this->actingAs($reseller)->getJson(route('reseller.payment.mpesa-status', [
            'invoice' => $invoice,
            'checkout_request_id' => $payment->transaction_reference,
        ]));

        $response->assertOk();
        $response->assertJson(['status' => 'completed']);

        $payment->refresh();
        $invoice->refresh();

        $this->assertSame('completed', $payment->status->value);
        $this->assertSame('paid', $invoice->status->value);
    }
}
