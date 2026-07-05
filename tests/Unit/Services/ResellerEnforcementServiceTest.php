<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerEnforcementService;
use App\Services\ResellerPackageSubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerEnforcementServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResellerEnforcementService $enforcement;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('grace_period_days', '5');
        Setting::setValue('reseller_suspend_on_overdue', 'true');
        Setting::setValue('reseller_cascade_suspend_on_overdue', 'true');
        Setting::setValue('reseller_suspend_excess_services', 'true');
        Setting::setValue('reseller_enforce_limits_on_provision', 'true');

        $this->enforcement = app(ResellerEnforcementService::class);
    }

    private function createPackage(int $maxServices = 2, int $maxUsers = 5): ResellerPackage
    {
        return ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => $maxServices,
            'max_users' => $maxUsers,
            'price' => 1000,
            'active' => true,
        ]);
    }

    public function test_suspend_reseller_marks_account_and_cascades_services(): void
    {
        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create(['provisioning_driver_key' => 'cpanel']);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
        ]);

        $cascade = $this->enforcement->suspendReseller($reseller);

        $reseller->refresh();
        $service->refresh();

        $this->assertTrue($reseller->isResellerSuspended());
        $this->assertSame(1, $cascade);
        $this->assertSame(ServiceStatus::Suspended, $service->status);
        $this->assertSame(
            ResellerEnforcementService::REASON_RESELLER_OVERDUE,
            $service->service_meta[ResellerEnforcementService::META_SUSPENSION_REASON]
        );
    }

    public function test_unsuspend_reseller_restores_cascade_suspended_services(): void
    {
        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
            'reseller_suspended_at' => now(),
            'reseller_suspension_reason' => ResellerEnforcementService::REASON_RESELLER_OVERDUE,
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create(['provisioning_driver_key' => 'cpanel']);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_RESELLER_OVERDUE,
            ],
        ]);

        $restored = $this->enforcement->unsuspendReseller($reseller);

        $reseller->refresh();
        $service->refresh();

        $this->assertFalse($reseller->isResellerSuspended());
        $this->assertSame(1, $restored);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertArrayNotHasKey(ResellerEnforcementService::META_SUSPENSION_REASON, $service->service_meta ?? []);
    }

    public function test_unsuspend_reseller_does_not_restore_customer_service_with_unpaid_invoice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10'));
        Setting::setValue('suspend_on_overdue', 'true');

        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
            'reseller_suspended_at' => now(),
            'reseller_suspension_reason' => ResellerEnforcementService::REASON_RESELLER_OVERDUE,
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create(['provisioning_driver_key' => 'cpanel']);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-06-09'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => $invoice->id,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_RESELLER_OVERDUE,
            ],
        ]);

        $restored = $this->enforcement->unsuspendReseller($reseller);

        $service->refresh();

        $this->assertFalse($reseller->fresh()->isResellerSuspended());
        $this->assertSame(0, $restored);
        $this->assertSame(ServiceStatus::Suspended, $service->status);

        Carbon::setTestNow();
    }

    public function test_excess_services_returns_only_slots_beyond_limit(): void
    {
        $package = $this->createPackage(maxServices: 1);
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'commenced_at' => now()->subDays(2),
        ]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'commenced_at' => now()->subDay(),
        ]);

        $excess = $this->enforcement->excessActiveServices($reseller);

        $this->assertCount(1, $excess);
    }

    public function test_assert_can_provision_blocks_suspended_reseller(): void
    {
        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'reseller_suspended_at' => now(),
        ]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'status' => ServiceStatus::Pending,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->enforcement->assertCanProvision($service);
    }

    public function test_subscription_payment_unsuspends_reseller(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01'));

        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_suspended_at' => now(),
        ]);

        $invoice = app(ResellerPackageSubscriptionService::class)
            ->createSubscriptionInvoice($reseller, $package);

        $invoice->update(['status' => 'paid', 'paid_date' => now()]);

        $reseller->refresh();

        $this->assertFalse($reseller->isResellerSuspended());
        $this->assertSame($package->id, $reseller->reseller_package_id);
        $this->assertTrue($reseller->package_expires_at?->isFuture() ?? false);

        Carbon::setTestNow();
    }

    public function test_should_suspend_reseller_when_subscription_invoice_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $reseller = User::factory()->reseller()->create([
            'package_expires_at' => now()->addMonth(),
        ]);

        Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'overdue',
            'due_date' => Carbon::parse('2026-06-01'),
        ]);

        $this->assertTrue($this->enforcement->shouldSuspendReseller($reseller));

        Carbon::setTestNow();
    }

    public function test_enforce_overdue_suspension_suspends_eligible_reseller(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15'));

        $reseller = User::factory()->reseller()->create([
            'package_expires_at' => now()->addMonth(),
        ]);

        Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'overdue',
            'due_date' => Carbon::parse('2026-06-01'),
        ]);

        $this->assertTrue($this->enforcement->enforceOverdueSuspension($reseller->fresh()));
        $this->assertTrue($reseller->fresh()->isResellerSuspended());

        Carbon::setTestNow();
    }

    public function test_enforce_overdue_suspension_is_noop_when_billing_current(): void
    {
        $reseller = User::factory()->reseller()->create([
            'package_expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($this->enforcement->enforceOverdueSuspension($reseller));
        $this->assertFalse($reseller->fresh()->isResellerSuspended());
    }
}
