<?php

namespace Tests\Unit\Models;

use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceUnpaidActivationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_unpaid_invoice_for_pending_service(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);
        $service = Service::factory()->create([
            'status' => 'pending',
            'invoice_id' => $invoice->id,
        ]);

        $this->assertTrue($service->unpaidActivationInvoice()->is($invoice));
    }

    public function test_returns_null_when_invoice_is_paid(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $service = Service::factory()->create([
            'status' => 'pending',
            'invoice_id' => $invoice->id,
        ]);

        $this->assertNull($service->unpaidActivationInvoice());
    }

    public function test_returns_null_for_active_service(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);
        $service = Service::factory()->create([
            'status' => 'active',
            'invoice_id' => $invoice->id,
        ]);

        $this->assertNull($service->unpaidActivationInvoice());
    }
}
