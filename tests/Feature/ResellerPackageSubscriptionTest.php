<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\Invoice;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerPackageSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function createPackage(array $overrides = []): ResellerPackage
    {
        return ResellerPackage::create(array_merge([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 10,
            'price' => 5300,
            'active' => true,
        ], $overrides));
    }

    private function createReseller(): User
    {
        return User::factory()->reseller()->create();
    }

    public function test_subscribe_creates_invoice_and_redirects_to_checkout_without_activating_package(): void
    {
        $reseller = $this->createReseller();
        $package = $this->createPackage();

        $response = $this->actingAs($reseller)->post(route('reseller.packages.subscribe', $package));

        $reseller->refresh();
        $invoice = Invoice::where('user_id', $reseller->id)->first();

        $response->assertRedirect(route('reseller.payment.select-method', $invoice));
        $this->assertNull($reseller->reseller_package_id);
        $this->assertNull($reseller->package_expires_at);
        $this->assertSame('reseller_subscription', $invoice->type);
        $this->assertSame('unpaid', $invoice->status->value);
        $this->assertTrue(
            $invoice->items()->where('product_type', 'reseller_package')->exists()
        );
        $packageItem = $invoice->items()->where('product_type', 'reseller_package')->first();
        $this->assertStringContainsString('Starter', $packageItem->description);
        $this->assertSame('Starter', $packageItem->displayTitle());
    }

    public function test_paid_subscription_invoice_activates_package(): void
    {
        $reseller = $this->createReseller();
        $package = $this->createPackage();
        $service = app(ResellerPackageSubscriptionService::class);

        $invoice = $service->createSubscriptionInvoice($reseller, $package);
        $invoice->update(['status' => 'paid', 'paid_date' => now()]);

        $reseller->refresh();

        $this->assertSame($package->id, $reseller->reseller_package_id);
        $this->assertNotNull($reseller->package_subscribed_at);
        $this->assertNotNull($reseller->package_expires_at);
    }

    public function test_renewal_invoice_extends_existing_expiry(): void
    {
        $reseller = $this->createReseller();
        $package = $this->createPackage();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $package);
        $reseller->refresh();
        $originalExpiry = $reseller->package_expires_at->copy();

        $renewal = $service->createSubscriptionInvoice($reseller, $package, renewal: true);
        $renewal->update(['status' => 'paid', 'paid_date' => now()]);

        $reseller->refresh();
        $this->assertTrue($reseller->package_expires_at->greaterThan($originalExpiry));
    }

    public function test_subscription_invoice_includes_admin_tax_when_enabled(): void
    {
        Setting::create(['key' => 'tax_enabled', 'value' => 'true']);
        Setting::create(['key' => 'tax_rate', 'value' => '16']);

        $reseller = $this->createReseller();
        $package = $this->createPackage(['price' => 5300]);
        $service = app(ResellerPackageSubscriptionService::class);

        $invoice = $service->createSubscriptionInvoice($reseller, $package);

        $this->assertSame(5300.0, (float) $invoice->subtotal);
        $this->assertSame(848.0, (float) $invoice->tax);
        $this->assertSame(6148.0, (float) $invoice->total);
    }

    public function test_subscription_invoice_has_no_tax_when_disabled(): void
    {
        Setting::create(['key' => 'tax_enabled', 'value' => 'false']);

        $reseller = $this->createReseller();
        $package = $this->createPackage(['price' => 5300]);
        $service = app(ResellerPackageSubscriptionService::class);

        $invoice = $service->createSubscriptionInvoice($reseller, $package);

        $this->assertSame(5300.0, (float) $invoice->subtotal);
        $this->assertSame(0.0, (float) $invoice->tax);
        $this->assertSame(5300.0, (float) $invoice->total);
    }

    public function test_renewal_invoice_due_date_matches_package_expiry(): void
    {
        Setting::setValue('reseller_package_invoice_advance_days', '10');

        $reseller = $this->createReseller();
        $package = $this->createPackage();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $package);
        $reseller->update(['package_expires_at' => now()->addDays(10)->startOfDay()]);
        $reseller->refresh();

        $invoice = $service->createSubscriptionInvoice($reseller, $package, renewal: true);

        $this->assertSame(
            $reseller->package_expires_at->toDateString(),
            $invoice->due_date->toDateString()
        );
    }

    public function test_generate_reseller_invoices_targets_packages_expiring_in_ten_days(): void
    {
        CronJob::create([
            'name' => 'Generate Reseller Package Invoices',
            'command' => 'cron:generate-reseller-invoices',
            'schedule' => '0 2 * * *',
            'enabled' => true,
        ]);

        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now()->subMonth(),
            'package_expires_at' => now()->addDays(10)->startOfDay(),
        ]);

        $this->artisan('cron:generate-reseller-invoices')->assertSuccessful();

        $this->assertDatabaseHas('invoices', [
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
        ]);
    }

    public function test_unpaid_upgrade_invoice_does_not_block_renewal_invoice_generation(): void
    {
        Setting::setValue('reseller_package_invoice_advance_days', '10');

        $starter = $this->createPackage(['name' => 'Starter', 'price' => 5300]);
        $pro = $this->createPackage(['name' => 'Pro', 'price' => 10600]);
        $reseller = $this->createReseller();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $starter);
        $reseller->update(['package_expires_at' => now()->addDays(5)->startOfDay()]);
        $reseller->refresh();

        $upgradeInvoice = $service->createSubscriptionInvoice($reseller, $pro);
        $this->assertStringContainsString(ResellerPackageSubscriptionService::UPGRADE_META, $upgradeInvoice->notes ?? '');

        $this->artisan('cron:generate-reseller-invoices')->assertSuccessful();

        $renewalInvoice = Invoice::query()
            ->where('user_id', $reseller->id)
            ->where('type', 'reseller_subscription')
            ->where('notes', 'like', '%Renewal%')
            ->first();

        $this->assertNotNull($renewalInvoice);
        $this->assertSame('unpaid', $renewalInvoice->status->value);
    }

    public function test_admin_can_generate_renewal_invoice_manually(): void
    {
        $package = $this->createPackage();
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now()->subMonth(),
            'package_expires_at' => now()->addDays(3)->startOfDay(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.resellers.generate-renewal-invoice', $reseller));

        $response->assertRedirect(route('admin.resellers.show', $reseller));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('invoices', [
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
        ]);
    }

    public function test_reseller_can_renew_current_package_early(): void
    {
        $package = $this->createPackage();
        $reseller = $this->createReseller();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $package);
        $reseller->update(['package_expires_at' => now()->addMonths(2)->startOfDay()]);
        $reseller->refresh();

        $response = $this->actingAs($reseller)->post(route('reseller.packages.renew'));

        $invoice = Invoice::query()
            ->where('user_id', $reseller->id)
            ->where('type', 'reseller_subscription')
            ->where('notes', 'like', '%Renewal%')
            ->first();

        $this->assertNotNull($invoice);
        $response->assertRedirect(route('reseller.payment.select-method', $invoice));
        $response->assertSessionHas('success');
    }

    public function test_renew_redirects_to_existing_pending_renewal_invoice(): void
    {
        $package = $this->createPackage();
        $reseller = $this->createReseller();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $package);
        $existing = $service->createRenewalInvoiceIfNeeded($reseller, force: true);

        $response = $this->actingAs($reseller)->post(route('reseller.packages.renew'));

        $response->assertRedirect(route('reseller.payment.select-method', $existing));
        $response->assertSessionHas('info');

        $this->assertSame(
            1,
            Invoice::query()
                ->where('user_id', $reseller->id)
                ->where('type', 'reseller_subscription')
                ->where('notes', 'like', '%Renewal%')
                ->count()
        );
    }

    public function test_renew_without_active_package_returns_error(): void
    {
        $reseller = $this->createReseller();

        $response = $this->actingAs($reseller)->post(route('reseller.packages.renew'));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_upgrade_invoice_charges_prorated_difference_not_full_package_price(): void
    {
        $starter = $this->createPackage(['name' => 'Starter', 'price' => 5300]);
        $pro = $this->createPackage(['name' => 'Pro', 'price' => 10600]);
        $reseller = $this->createReseller();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $starter);
        $reseller->update(['package_expires_at' => now()->addDays(15)->startOfDay()]);
        $reseller->refresh();

        $invoice = $service->createSubscriptionInvoice($reseller, $pro);

        $expectedSubtotal = round((10600 - 5300) * (15 / 30), 2);
        $this->assertStringContainsString(ResellerPackageSubscriptionService::UPGRADE_META, $invoice->notes ?? '');
        $this->assertSame($expectedSubtotal, (float) $invoice->subtotal);
        $this->assertLessThan((float) $pro->price, (float) $invoice->subtotal);
    }

    public function test_paid_upgrade_invoice_keeps_package_expiry_date(): void
    {
        $starter = $this->createPackage(['name' => 'Starter', 'price' => 5300]);
        $pro = $this->createPackage(['name' => 'Pro', 'price' => 10600]);
        $reseller = $this->createReseller();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $starter);
        $expiry = now()->addDays(180)->startOfDay();
        $reseller->update(['package_expires_at' => $expiry]);
        $reseller->refresh();

        $invoice = $service->createSubscriptionInvoice($reseller, $pro);
        $invoice->update(['status' => 'paid', 'paid_date' => now()]);

        $service->activateFromPaidInvoice($invoice->fresh());
        $reseller->refresh();

        $this->assertSame($pro->id, $reseller->reseller_package_id);
        $this->assertSame($expiry->toDateString(), $reseller->package_expires_at->toDateString());
    }

    public function test_annual_upgrade_invoice_uses_365_day_cycle_for_proration(): void
    {
        $starter = $this->createPackage(['name' => 'Starter Annual', 'price' => 50000, 'billing_cycle' => 'annually']);
        $pro = $this->createPackage(['name' => 'Pro Annual', 'price' => 100000, 'billing_cycle' => 'annually']);
        $reseller = $this->createReseller();
        $service = app(ResellerPackageSubscriptionService::class);

        $service->activateSubscription($reseller, $starter);
        $reseller->update(['package_expires_at' => now()->addDays(182)->startOfDay()]);
        $reseller->refresh();

        $invoice = $service->createSubscriptionInvoice($reseller, $pro);

        $expectedSubtotal = round((100000 - 50000) * (182 / 365), 2);
        $this->assertSame($expectedSubtotal, (float) $invoice->subtotal);
    }
}
