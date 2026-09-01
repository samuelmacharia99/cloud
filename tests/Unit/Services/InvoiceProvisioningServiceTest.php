<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatus;
use App\Jobs\ProvisionContainerServiceJob;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_reseller_hosting_invoice_auto_provisions_when_global_auto_provision_is_off(): void
    {
        Setting::setValue('auto_provision', 'false');
        Setting::setValue('reseller_auto_provision_hosting', 'true');
        Setting::setValue('reseller_enforce_limits_on_provision', 'false');

        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);
        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'provisioning_driver_key' => 'container',
        ]);

        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'provisioning_driver_key' => 'container',
        ]);

        Bus::fake();

        $result = app(InvoiceProvisioningService::class)->provisionPendingServicesForInvoice($invoice);

        $this->assertSame(1, $result['provisioned']);
        $this->assertFalse($result['skipped']);
        Bus::assertDispatched(
            ProvisionContainerServiceJob::class,
            fn (ProvisionContainerServiceJob $job) => $job->serviceId === $service->id
        );
        $this->assertSame('provisioning', $service->fresh()->status->value ?? $service->fresh()->status);
    }

    public function test_platform_invoice_skips_auto_provision_when_global_toggle_is_off(): void
    {
        Setting::setValue('auto_provision', 'false');
        Setting::setValue('reseller_auto_provision_hosting', 'true');

        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);
        $product = Product::factory()->create([
            'type' => 'shared_hosting',
            'provisioning_driver_key' => 'directadmin',
        ]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'provisioning_driver_key' => 'directadmin',
        ]);

        $result = app(InvoiceProvisioningService::class)->provisionPendingServicesForInvoice($invoice);

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['provisioned']);
    }
}
