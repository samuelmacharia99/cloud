<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
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
            'status' => 'overdue',
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
