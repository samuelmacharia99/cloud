<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('provisioning_mode', 'automatic');
        Setting::setValue('auto_provision', 'true');
    }

    public function test_provisions_pending_services_when_invoice_is_paid(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $product = Product::factory()->create(['provisioning_driver_key' => 'directadmin']);

        $service = Service::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $this->mock(ProvisioningService::class, function ($mock) use ($service) {
            $mock->shouldReceive('provision')
                ->once()
                ->with(\Mockery::on(fn ($passed) => $passed->id === $service->id));
        });

        $result = app(InvoiceProvisioningService::class)->provisionPendingServicesForInvoice($invoice);

        $this->assertSame(1, $result['provisioned']);
        $this->assertFalse($result['skipped']);
        $this->assertSame([], $result['failed']);
    }

    public function test_invoice_is_paid_enough_when_status_is_backed_enum(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $service = Service::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => ServiceStatus::Pending,
        ]);

        $this->assertTrue(
            app(InvoiceProvisioningService::class)->invoiceIsPaidEnoughForProvisioning($service)
        );
    }
}
