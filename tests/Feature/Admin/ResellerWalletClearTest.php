<?php

namespace Tests\Feature\Admin;

use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\ResellerWalletService;
use App\Services\WalletNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResellerWalletClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_remove_part_of_reseller_wallet_balance_with_audited_adjustment(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->reseller();
        $wallet = app(ResellerWalletService::class)->getOrCreate($reseller);
        $wallet->update(['balance' => 1470]);

        $notifications = Mockery::mock(WalletNotificationService::class);
        $notifications->shouldReceive('sendManualAdjustmentNotification')
            ->once()
            ->with(
                Mockery::on(fn ($transaction) => (float) $transaction->balance_before === 1470.0
                    && (float) $transaction->balance_after === 70.0),
                -1400.0
            );
        $this->app->instance(WalletNotificationService::class, $notifications);

        $response = $this->actingAs($admin)->post(
            route('admin.resellers.wallet-clear', $reseller),
            [
                '_form' => 'clear_wallet',
                'amount' => 1400,
                'reason' => 'Fix',
                'confirm_removal' => '1',
            ]
        );

        $response
            ->assertRedirect(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'wallet']))
            ->assertSessionHas('success');
        $this->assertSame(70.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'adjustment',
            'amount' => 1400,
            'balance_before' => 1470,
            'balance_after' => 70,
            'performed_by' => $admin->id,
            'description' => 'Fix',
            'status' => 'completed',
        ]);

        $transaction = $wallet->transactions()->sole();
        $this->assertSame('remove_balance', $transaction->metadata['operation']);
        $this->assertSame(1400.0, (float) $transaction->metadata['removed_amount']);
    }

    public function test_confirmation_is_required_and_balance_remains_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->reseller();
        $wallet = app(ResellerWalletService::class)->getOrCreate($reseller);
        $wallet->update(['balance' => 500]);

        $this->actingAs($admin)
            ->from(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'wallet']))
            ->post(route('admin.resellers.wallet-clear', $reseller), [
                '_form' => 'clear_wallet',
                'amount' => 100,
                'reason' => 'Remove an incorrect wallet amount.',
            ])
            ->assertSessionHasErrors('confirm_removal');

        $this->assertSame(500.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_amount_above_available_balance_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->reseller();
        $wallet = app(ResellerWalletService::class)->getOrCreate($reseller);
        $wallet->update(['balance' => 500]);

        $this->actingAs($admin)
            ->post(route('admin.resellers.wallet-clear', $reseller), [
                '_form' => 'clear_wallet',
                'amount' => 501,
                'confirm_removal' => '1',
            ])
            ->assertSessionHas('error', 'The amount to remove cannot exceed the available wallet balance.');

        $this->assertSame(500.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_reseller_page_shows_remove_balance_action_only_when_funded(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = $this->reseller();
        $wallet = app(ResellerWalletService::class)->getOrCreate($reseller);
        $wallet->update(['balance' => 700]);

        $this->actingAs($admin)
            ->get(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'wallet']))
            ->assertOk()
            ->assertSee('Remove wallet balance')
            ->assertSee('Amount to remove (KES)')
            ->assertSee('Available: KES 700.00');
    }

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Wallet package',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }
}
