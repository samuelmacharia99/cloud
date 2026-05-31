<?php

namespace Tests\Feature;

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

    public function test_generate_reseller_invoices_targets_packages_expiring_in_five_days(): void
    {
        $package = $this->createPackage();
        $reseller = User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now()->subMonth(),
            'package_expires_at' => now()->addDays(5)->startOfDay(),
        ]);

        $this->artisan('cron:generate-reseller-invoices')->assertSuccessful();

        $this->assertDatabaseHas('invoices', [
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'unpaid',
        ]);
    }
}
