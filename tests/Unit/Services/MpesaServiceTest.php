<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentGateway\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MpesaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('mpesa_enabled', '1');
        Setting::setValue('mpesa_environment', 'sandbox');
        Setting::setValue('mpesa_shortcode', '174379');
        Setting::setValue('mpesa_consumer_key', 'test-key');
        Setting::setValue('mpesa_consumer_secret', 'test-secret');
        Setting::setValue('mpesa_passkey', 'test-passkey');
        Setting::setValue('site_url', 'https://example.test');
        Setting::setValue('mpesa_callback_token', 'callback-token');
    }

    public function test_initiate_fails_when_stk_response_not_accepted(): void
    {
        Cache::flush();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token-1'], 200),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '1',
                'ResponseDescription' => 'Invalid initiator information',
            ], 200),
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'unpaid',
            'total' => 1000,
        ]);

        $result = app(MpesaService::class)->initiate($invoice, ['phone' => '0712345678']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid initiator information', $result['message']);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_initiate_creates_pending_payment_when_stk_is_accepted(): void
    {
        Cache::flush();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token-2'], 200),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'ws_CO_123456789',
                'ResponseDescription' => 'Success. Request accepted for processing',
            ], 200),
        ]);

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 1500,
        ]);

        $result = app(MpesaService::class)->initiate($invoice, ['phone' => '0712345678']);

        $this->assertTrue($result['success']);
        $this->assertSame('ws_CO_123456789', $result['checkout_request_id']);
        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'mpesa',
            'transaction_reference' => 'ws_CO_123456789',
            'status' => 'pending',
        ]);
    }

    public function test_verify_maps_non_zero_result_to_failed(): void
    {
        Cache::flush();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'token-3'], 200),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResultCode' => '1032',
                'ResultDesc' => 'Request cancelled by user',
            ], 200),
        ]);

        $result = app(MpesaService::class)->verify('ws_CO_cancelled_1');

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('1032', $result['response_code']);
    }
}

