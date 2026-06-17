<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PaymentGateway\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
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

    public function test_handle_callback_completes_payment_without_marking_invoice_paid(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 1500,
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 1500,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'transaction_reference' => 'ws_CO_success_1',
            'status' => 'pending',
        ]);

        $result = app(MpesaService::class)->handleCallback([
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_success_1',
                    'ResultCode' => 0,
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123'],
                            ['Name' => 'TransactionDate', 'Value' => 20260617100000],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame($payment->id, $result['payment_id']);

        $payment->refresh();
        $invoice->refresh();

        $this->assertTrue($payment->isCompleted());
        $this->assertSame('unpaid', $invoice->status->value ?? $invoice->status);
    }

    public function test_handle_callback_failure_sends_payment_failed_notification(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'unpaid',
            'total' => 500,
        ]);

        Payment::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'transaction_reference' => 'ws_CO_failed_1',
            'status' => 'pending',
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notifyPaymentFailed')
            ->once()
            ->withArgs(function ($payment, $reason) {
                return $payment->transaction_reference === 'ws_CO_failed_1'
                    && str_contains($reason, 'Request cancelled by user');
            });
        $this->app->instance(NotificationService::class, $notifications);

        $result = app(MpesaService::class)->handleCallback([
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => 'ws_CO_failed_1',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('payments', [
            'transaction_reference' => 'ws_CO_failed_1',
            'status' => 'failed',
        ]);
    }
}
