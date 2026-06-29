<?php

namespace Tests\Unit\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSettlementRenewalBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_extends_next_due_date_from_current_period_after_renewal_payment(): void
    {
        Carbon::setTestNow('2026-03-25 10:00:00');

        $user = User::factory()->customer()->create();
        $product = Product::factory()->create(['monthly_price' => 1000]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-03-31',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 1000,
            'subtotal' => 1000,
            'tax' => 0,
            'notes' => 'Auto-generated renewal invoice.',
        ]);

        $service->update(['invoice_id' => $invoice->id]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Hosting — monthly renewal',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $service->refresh();

        $this->assertSame('2026-04-30', $service->next_due_date->toDateString());
    }

    public function test_checkout_invoices_do_not_advance_next_due_date_on_first_payment(): void
    {
        Carbon::setTestNow('2026-03-25 10:00:00');

        $user = User::factory()->customer()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-04-25',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 1000,
            'subtotal' => 1000,
            'tax' => 0,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $service->update(['invoice_id' => $invoice->id]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'New hosting order',
            'amount' => 1000,
            'unit_price' => 1000,
            'quantity' => 1,
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'status' => PaymentStatus::Completed,
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $service->refresh();

        $this->assertSame('2026-04-25', $service->next_due_date->toDateString());
        $this->assertTrue($order->fresh()->isPaid() || $order->fresh()->payment_status === 'paid');
    }

    public function test_hosting_upgrade_invoices_do_not_advance_next_due_date(): void
    {
        Carbon::setTestNow('2026-03-25 10:00:00');

        $user = User::factory()->customer()->create();
        $product = Product::factory()->create();

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-03-31',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 500,
            'subtotal' => 500,
            'tax' => 0,
            'notes' => 'Hosting upgrade: Silver → Gold',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'description' => 'Hosting upgrade',
            'amount' => 500,
            'unit_price' => 500,
            'quantity' => 1,
            'custom_options' => [
                'hosting_upgrade' => true,
                'hosting_plan_change' => true,
                'to_product_id' => $product->id,
            ],
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'status' => PaymentStatus::Completed,
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $service->refresh();

        $this->assertSame('2026-03-31', $service->next_due_date->toDateString());
    }

    public function test_renewal_with_upgrade_invoice_advances_next_due_date(): void
    {
        Carbon::setTestNow('2026-03-25 10:00:00');

        $user = User::factory()->customer()->create();
        $bronze = Product::factory()->create(['monthly_price' => 1000]);
        $silver = Product::factory()->create(['monthly_price' => 2000]);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $bronze->id,
            'status' => ServiceStatus::Active,
            'billing_cycle' => 'monthly',
            'next_due_date' => '2026-03-31',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 2000,
            'subtotal' => 2000,
            'tax' => 0,
            'notes' => 'Renewal with upgrade — Bronze → Silver (Monthly)',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $silver->id,
            'description' => 'Silver — Monthly renewal (plan upgrade)',
            'amount' => 2000,
            'unit_price' => 2000,
            'quantity' => 1,
            'custom_options' => [
                'hosting_renewal_upgrade' => true,
                'hosting_upgrade' => true,
                'hosting_plan_change' => true,
                'to_product_id' => $silver->id,
            ],
        ]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'amount' => 2000,
            'status' => PaymentStatus::Completed,
        ]);

        app(InvoiceSettlementService::class)->settleFromPayment($payment);

        $service->refresh();

        $this->assertSame('2026-04-30', $service->next_due_date->toDateString());
    }
}
