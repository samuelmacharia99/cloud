<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\ResellerWalletService;
use App\Services\WalletNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResellerWalletTopupIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_process_topup_payment_is_idempotent(): void
    {
        $reseller = User::factory()->reseller()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 500,
            'subtotal' => 500,
            'tax' => 0,
        ]);

        $payment = Payment::create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'wallet_topup',
            'transaction_reference' => 'ws_CO_idempotent_test',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $mock = Mockery::mock(WalletNotificationService::class);
        $mock->shouldReceive('sendTopupConfirmation')->twice();
        $this->app->instance(WalletNotificationService::class, $mock);

        $service = app(ResellerWalletService::class);

        $service->processTopupPayment($payment);
        $service->processTopupPayment($payment->fresh());

        $wallet = $reseller->fresh()->wallet;
        $this->assertSame(500.0, (float) $wallet->balance);
        $this->assertSame(1, $wallet->transactions()->where('reference_id', $payment->id)->count());
    }
}
