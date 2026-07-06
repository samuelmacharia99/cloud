<?php

namespace Tests\Feature\Admin;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvoiceTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_transfer_invoice_to_another_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $fromCustomer = User::factory()->create(['name' => 'Alice']);
        $toCustomer = User::factory()->create(['name' => 'Bob']);

        $invoice = Invoice::factory()->create([
            'user_id' => $fromCustomer->id,
            'invoice_number' => 'INV-284',
            'status' => 'unpaid',
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $fromCustomer->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'manual',
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.invoices.transfer', $invoice), [
                'target_user_id' => $toCustomer->id,
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHas('success');

        $this->assertSame($toCustomer->id, $invoice->fresh()->user_id);
        $this->assertSame($toCustomer->id, $payment->fresh()->user_id);
        $this->assertStringContainsString('Bob', (string) $invoice->fresh()->notes);

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'invoice.transfer',
            'subject_type' => Invoice::class,
            'subject_id' => $invoice->id,
        ]);
    }

    public function test_invoice_transfer_moves_linked_services(): void
    {
        $admin = User::factory()->admin()->create();
        $fromCustomer = User::factory()->create();
        $toCustomer = User::factory()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $fromCustomer->id,
            'status' => 'unpaid',
        ]);
        $service = Service::factory()->create(['user_id' => $fromCustomer->id]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'description' => 'Hosting',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.invoices.transfer', $invoice), [
                'target_user_id' => $toCustomer->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($toCustomer->id, $service->fresh()->user_id);
    }

    public function test_cannot_transfer_invoice_to_same_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $invoice))
            ->post(route('admin.invoices.transfer', $invoice), [
                'target_user_id' => $customer->id,
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHas('error');

        $this->assertSame($customer->id, $invoice->fresh()->user_id);
    }
}
