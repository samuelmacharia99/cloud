<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ResellerEnforcementService;
use App\Services\ResellerPackageSubscriptionService;
use App\Services\ResellerSubscriptionAutoPayService;
use App\Services\ResellerWalletService;
use App\Services\WalletNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResellerSubscriptionAutoPayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->mock(WalletNotificationService::class, function ($mock) {
            $mock->shouldReceive('sendManualAdjustmentNotification')->andReturnNull();
            $mock->shouldReceive('sendSubscriptionAutoPayNotification')->andReturnNull();
        });

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyPaymentReceived')->andReturnNull();
        });

        $this->mock(ResellerEnforcementService::class, function ($mock) {
            $mock->shouldReceive('handleSubscriptionPaid')->andReturnNull();
        });
    }

    private function createPackage(): ResellerPackage
    {
        return ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 10,
            'max_users' => 5,
            'price' => 5300,
            'active' => true,
        ]);
    }

    public function test_renewal_invoice_auto_paid_when_wallet_covers_full_amount(): void
    {
        Setting::setValue('reseller_auto_pay_subscription_from_wallet', 'true');
        Setting::setValue('tax_enabled', 'false');

        $reseller = User::factory()->reseller()->create();
        $package = $this->createPackage();

        app(ResellerPackageSubscriptionService::class)->activateSubscription($reseller, $package);
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 10000]);

        $invoice = app(ResellerPackageSubscriptionService::class)->createSubscriptionInvoice($reseller, $package, renewal: true);

        $this->assertTrue($invoice->isPaid());
        $this->assertSame(5300.0, (float) $invoice->wallet_amount_applied);
        $this->assertSame(4700.0, (float) $reseller->fresh()->wallet->balance);
    }

    public function test_renewal_invoice_stays_unpaid_when_wallet_is_insufficient(): void
    {
        Setting::setValue('reseller_auto_pay_subscription_from_wallet', 'true');
        Setting::setValue('tax_enabled', 'false');

        $reseller = User::factory()->reseller()->create();
        $package = $this->createPackage();

        app(ResellerPackageSubscriptionService::class)->activateSubscription($reseller, $package);
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 1000]);

        $invoice = app(ResellerPackageSubscriptionService::class)->createSubscriptionInvoice($reseller, $package, renewal: true);

        $this->assertFalse($invoice->isPaid());
        $this->assertSame('unpaid', $invoice->status->value);
        $this->assertSame(0.0, (float) $invoice->wallet_amount_applied);
    }

    public function test_auto_pay_skipped_when_disabled(): void
    {
        Setting::setValue('reseller_auto_pay_subscription_from_wallet', 'false');
        Setting::setValue('tax_enabled', 'false');

        $reseller = User::factory()->reseller()->create();
        $package = $this->createPackage();

        app(ResellerPackageSubscriptionService::class)->activateSubscription($reseller, $package);
        app(ResellerWalletService::class)->getOrCreate($reseller)->update(['balance' => 20000]);

        $invoice = Invoice::create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'invoice_number' => 'INV-TEST001',
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
            'subtotal' => 5300,
            'tax' => 0,
            'total' => 5300,
            'notes' => 'Renewal [package:'.$package->id.']',
        ]);

        $this->assertFalse(app(ResellerSubscriptionAutoPayService::class)->attempt($invoice));
        $this->assertFalse($invoice->fresh()->isPaid());
    }
}
