<?php

namespace Tests\Unit\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerWalletService;
use App\Services\WalletNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResellerWalletAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_adjust_rejects_balance_below_zero(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = User::factory()->reseller()->create();

        app(ResellerWalletService::class)->adjust($reseller, 1000, 'Initial test credit from admin', $admin);

        $this->expectException(InsufficientFundsException::class);

        app(ResellerWalletService::class)->adjust($reseller, -2000, 'Attempt overdraft deduction', $admin);
    }

    public function test_adjust_sends_notification(): void
    {
        Setting::setValue('notify_reseller_wallet_adjustment', 'true');

        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = User::factory()->reseller()->create(['phone' => '254712345678']);

        $mock = Mockery::mock(WalletNotificationService::class);
        $mock->shouldReceive('sendManualAdjustmentNotification')->once();
        $this->app->instance(WalletNotificationService::class, $mock);

        app(ResellerWalletService::class)->adjust($reseller, 500, 'Manual top-up for testing', $admin);

        $this->assertSame(500.0, (float) $reseller->fresh()->wallet->balance);
    }

    public function test_process_topup_payment_credits_wallet_even_if_notification_fails(): void
    {
        $reseller = User::factory()->reseller()->create(['phone' => '254712345678']);
        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'unpaid',
            'total' => 1000,
            'subtotal' => 1000,
            'tax' => 0,
        ]);

        $payment = Payment::create([
            'user_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'wallet_topup',
            'transaction_reference' => 'TEST-TOPUP-001',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $mock = Mockery::mock(WalletNotificationService::class);
        $mock->shouldReceive('sendTopupConfirmation')->once()->andThrow(new \RuntimeException('Notification transport failed'));
        $this->app->instance(WalletNotificationService::class, $mock);

        app(ResellerWalletService::class)->processTopupPayment($payment);

        $wallet = $reseller->fresh()->wallet;
        $this->assertNotNull($wallet);
        $this->assertSame(1000.0, (float) $wallet->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'deposit',
            'reference_id' => $payment->id,
            'reference_type' => 'Payment',
        ]);
        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_process_topup_payment_credits_the_paying_reseller_wallet_not_the_first_wallet_row(): void
    {
        $firstReseller = User::factory()->reseller()->create();
        $payingReseller = User::factory()->reseller()->create();

        app(ResellerWalletService::class)->getOrCreate($firstReseller);
        app(ResellerWalletService::class)->getOrCreate($payingReseller);

        $invoice = Invoice::create([
            'user_id' => $payingReseller->id,
            'invoice_number' => 'TOPUP-'.strtoupper(uniqid()),
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => 250,
            'tax' => 0,
            'total' => 250,
        ]);

        $payment = Payment::create([
            'user_id' => $payingReseller->id,
            'invoice_id' => $invoice->id,
            'amount' => 250,
            'currency' => 'KES',
            'payment_method' => 'mpesa',
            'payment_purpose' => 'wallet_topup',
            'transaction_reference' => 'TEST-TOPUP-MULTI-'.uniqid(),
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $mock = Mockery::mock(WalletNotificationService::class);
        $mock->shouldReceive('sendTopupConfirmation')->once();
        $this->app->instance(WalletNotificationService::class, $mock);

        app(ResellerWalletService::class)->processTopupPayment($payment);

        $firstWallet = $firstReseller->fresh()->wallet;
        $payingWallet = $payingReseller->fresh()->wallet;

        $this->assertNotNull($firstWallet);
        $this->assertNotNull($payingWallet);
        $this->assertSame(0.0, (float) $firstWallet->balance);
        $this->assertSame(250.0, (float) $payingWallet->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $payingWallet->id,
            'type' => 'deposit',
            'reference_id' => $payment->id,
            'reference_type' => 'Payment',
        ]);
    }
}
