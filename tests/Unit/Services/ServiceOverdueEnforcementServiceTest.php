<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\ResellerEnforcementService;
use App\Services\ServiceOverdueEnforcementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOverdueEnforcementServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceOverdueEnforcementService $enforcement;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::create(['key' => 'suspend_on_overdue', 'value' => 'true']);
        Setting::create(['key' => 'grace_period_days', 'value' => '5']);

        $this->enforcement = app(ServiceOverdueEnforcementService::class);
    }

    public function test_finds_reseller_customer_service_via_linked_invoice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-04-01'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'invoice_id' => $invoice->id,
        ]);

        $matches = $this->enforcement->activeServicesWithOverdueInvoicePastGraceQuery()->get();

        $this->assertTrue($this->enforcement->isResellerManagedService($service));
        $this->assertTrue($matches->contains('id', $service->id));

        Carbon::setTestNow();
    }

    public function test_finds_reseller_customer_service_via_invoice_item_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        $oldInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'due_date' => Carbon::parse('2026-03-01'),
        ]);

        $overdueInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'overdue',
            'due_date' => Carbon::parse('2026-04-01'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'invoice_id' => $oldInvoice->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $overdueInvoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $matches = $this->enforcement->activeServicesWithOverdueInvoicePastGraceQuery()->get();

        $this->assertTrue($matches->contains('id', $service->id));

        Carbon::setTestNow();
    }

    public function test_unsuspend_finds_suspended_reseller_customer_service_from_invoice_item(): void
    {
        $reseller = User::factory()->create(['is_reseller' => true]);
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = Product::factory()->create();

        $paidInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => null,
        ]);

        InvoiceItem::create([
            'invoice_id' => $paidInvoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $services = $this->enforcement->suspendedServicesForPaidInvoice($paidInvoice);

        $this->assertCount(1, $services);
        $this->assertSame($service->id, $services->first()->id);
    }

    public function test_active_services_for_invoice_includes_line_item_services(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create(['provisioning_driver_key' => 'directadmin']);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'overdue',
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'provisioning_driver_key' => 'directadmin',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Hosting renewal',
            'quantity' => 1,
            'unit_price' => 500,
            'amount' => 500,
        ]);

        $services = $this->enforcement->activeServicesForInvoice($invoice);

        $this->assertCount(1, $services);
        $this->assertTrue($this->enforcement->isDirectAdminService($service));
    }

    public function test_suspension_enabled_when_admin_setting_saved_as_checkbox_value(): void
    {
        Setting::where('key', 'suspend_on_overdue')->update(['value' => '1']);

        $this->assertTrue($this->enforcement->isSuspensionEnabled());
    }

    public function test_disk_overquota_suspension_blocks_payment_unsuspend(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $paidInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => $paidInvoice->id,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_DISK_OVERQUOTA,
            ],
        ]);

        $this->assertFalse($this->enforcement->canAutoUnsuspendForPaidInvoice($service));
    }

    public function test_cannot_auto_unsuspend_when_unpaid_invoice_is_past_due_but_not_marked_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10'));

        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-06-09'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => $invoice->id,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_INVOICE_OVERDUE,
            ],
        ]);

        $this->assertTrue($this->enforcement->shouldSuspendForOverdueInvoice($service));
        $this->assertFalse($this->enforcement->canAutoUnsuspendForPaidInvoice($service));

        Carbon::setTestNow();
    }

    public function test_cannot_auto_unsuspend_when_old_invoice_paid_but_renewal_invoice_unpaid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10'));

        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $paidInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'due_date' => Carbon::parse('2026-05-01'),
        ]);

        $unpaidRenewal = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-06-09'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => $paidInvoice->id,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_INVOICE_OVERDUE,
            ],
        ]);

        InvoiceItem::create([
            'invoice_id' => $unpaidRenewal->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $this->assertFalse($this->enforcement->canAutoUnsuspendForPaidInvoice($service));

        Carbon::setTestNow();
    }

    public function test_unsuspend_query_excludes_service_with_unpaid_renewal_and_paid_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 08:00:05'));

        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $paidInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'due_date' => Carbon::parse('2026-05-25'),
        ]);

        $unpaidRenewal = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'due_date' => Carbon::parse('2026-06-25'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => $unpaidRenewal->id,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_INVOICE_OVERDUE,
            ],
        ]);

        InvoiceItem::create([
            'invoice_id' => $paidInvoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Previous period',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        InvoiceItem::create([
            'invoice_id' => $unpaidRenewal->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $matches = $this->enforcement->suspendedServicesWithPaidBillingInvoiceQuery()->get();

        $this->assertFalse($matches->contains('id', $service->id));
        $this->assertFalse($this->enforcement->canAutoUnsuspendForPaidInvoice($service));

        Carbon::setTestNow();
    }

    public function test_can_auto_unsuspend_after_all_billing_invoices_are_paid(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create();

        $paidInvoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'invoice_id' => $paidInvoice->id,
            'service_meta' => [
                ResellerEnforcementService::META_SUSPENSION_REASON => ResellerEnforcementService::REASON_INVOICE_OVERDUE,
            ],
        ]);

        $this->assertTrue($this->enforcement->canAutoUnsuspendForPaidInvoice($service));
    }

    public function test_reseller_subscription_invoice_does_not_select_customer_services(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10'));

        $reseller = User::factory()->create(['is_reseller' => true]);
        $product = Product::factory()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'type' => 'reseller_subscription',
            'status' => 'overdue',
            'due_date' => Carbon::parse('2026-04-01'),
        ]);

        $service = Service::factory()->create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'invoice_id' => $invoice->id,
        ]);

        $matches = $this->enforcement->activeServicesWithOverdueInvoicePastGraceQuery()->get();

        $this->assertFalse($matches->contains('id', $service->id));

        Carbon::setTestNow();
    }
}
