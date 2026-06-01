<?php

namespace Tests\Unit\Services;

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

        $this->expectException(\App\Exceptions\InsufficientFundsException::class);

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
}
