<?php

namespace Tests\Unit\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ServiceBillingDateRepairService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceBillingDateRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_service_with_stale_next_due_date_after_paid_renewal(): void
    {
        Carbon::setTestNow('2026-06-26');

        $user = User::factory()->customer()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-06-25',
        ]);

        $paid = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Paid,
            'due_date' => '2026-06-25',
            'paid_date' => '2026-06-25',
            'notes' => 'Auto-generated renewal invoice.',
        ]);

        $service->update(['invoice_id' => $paid->id]);

        InvoiceItem::create([
            'invoice_id' => $paid->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Bronze — Monthly',
            'quantity' => 1,
            'unit_price' => 380,
            'amount' => 380,
        ]);

        $duplicate = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Overdue,
            'due_date' => '2026-06-25',
            'notes' => 'Auto-generated renewal invoice.',
        ]);

        InvoiceItem::create([
            'invoice_id' => $duplicate->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Bronze — Monthly',
            'quantity' => 1,
            'unit_price' => 380,
            'amount' => 380,
        ]);

        $repair = app(ServiceBillingDateRepairService::class);
        $affected = $repair->findAffected();

        $this->assertCount(1, $affected);
        $this->assertSame($service->id, $affected->first()['service']->id);
        $this->assertSame('2026-07-25', $affected->first()['expected_next_due_date']);
        $this->assertCount(1, $affected->first()['duplicate_invoices']);

        $repair->repair($service, $paid, cancelDuplicates: true, dryRun: false);

        $service->refresh();
        $duplicate->refresh();

        $this->assertSame('2026-07-25', $service->next_due_date->toDateString());
        $this->assertSame(InvoiceStatus::Cancelled, $duplicate->status);

        Carbon::setTestNow();
    }

    public function test_skips_service_when_next_due_date_already_advanced(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-07-25',
        ]);

        $paid = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Paid,
            'due_date' => '2026-06-25',
            'paid_date' => '2026-06-25',
            'notes' => 'Auto-generated renewal invoice.',
        ]);

        InvoiceItem::create([
            'invoice_id' => $paid->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Hosting — Monthly',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
        ]);

        $this->assertCount(0, app(ServiceBillingDateRepairService::class)->findAffected());
    }

    public function test_mislinked_repair_skips_legitimate_new_period_renewal_invoice(): void
    {
        Carbon::setTestNow('2026-06-26');

        $user = User::factory()->customer()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Suspended,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-07-01',
        ]);

        $paid = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Paid,
            'due_date' => '2026-06-01',
            'paid_date' => '2026-06-01',
            'notes' => 'Auto-generated renewal invoice.',
        ]);

        InvoiceItem::create([
            'invoice_id' => $paid->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Bronze — Monthly',
            'quantity' => 1,
            'unit_price' => 380,
            'amount' => 380,
        ]);

        $currentRenewal = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'due_date' => '2026-07-01',
            'notes' => 'Auto-generated renewal invoice.',
        ]);

        $service->update(['invoice_id' => $currentRenewal->id]);

        InvoiceItem::create([
            'invoice_id' => $currentRenewal->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Bronze — Monthly',
            'quantity' => 1,
            'unit_price' => 380,
            'amount' => 380,
        ]);

        $repair = app(ServiceBillingDateRepairService::class);

        $this->assertCount(0, $repair->findMislinkedRenewalServices());

        Carbon::setTestNow();
    }
}
