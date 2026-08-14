<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminPaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_and_reject_pending_manual_payments(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $customer = User::factory()->customer()->create(['preferred_currency' => 'KES']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'currency' => 'KES',
            'total' => 1000,
        ]);

        $approved = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'manual',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'KES',
        ]);
        $rejected = Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_method' => 'manual',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'KES',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.payments.approve-manual', $approved))
            ->assertSessionHas('success');
        $this->assertSame(PaymentStatus::Completed, $approved->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.payments.reject-manual', $rejected), [
                'rejection_reason' => 'Proof could not be verified.',
            ])
            ->assertSessionHas('success');
        $this->assertSame(PaymentStatus::Failed, $rejected->fresh()->status);
    }

    public function test_mark_as_paid_uses_remaining_amount_currency_and_unique_reference(): void
    {
        Mail::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $customer = User::factory()->customer()->create(['preferred_currency' => 'USD']);

        $first = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'currency' => 'USD',
            'exchange_rate' => 0.01,
            'subtotal' => 10,
            'tax' => 0,
            'total' => 10,
            'total_base_kes' => 1000,
        ]);
        Payment::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $first->id,
            'payment_method' => 'manual',
            'status' => 'completed',
            'amount' => 4,
            'currency' => 'USD',
        ]);
        $second = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'currency' => 'USD',
            'exchange_rate' => 0.01,
            'subtotal' => 8,
            'tax' => 0,
            'total' => 8,
            'total_base_kes' => 800,
        ]);

        $this->actingAs($admin)->post(route('admin.invoices.mark-paid', $first))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.invoices.mark-paid', $second))->assertSessionHas('success');

        $firstPayment = $first->payments()->latest('id')->firstOrFail();
        $secondPayment = $second->payments()->latest('id')->firstOrFail();
        $this->assertSame(6.0, (float) $firstPayment->amount);
        $this->assertSame('USD', $firstPayment->currency);
        $this->assertNotSame($firstPayment->transaction_reference, $secondPayment->transaction_reference);
    }

    public function test_admin_cannot_assign_payment_to_another_users_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        $selectedUser = User::factory()->customer()->create();
        $invoiceOwner = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $invoiceOwner->id]);

        $response = $this->actingAs($admin)->post(route('admin.payments.store'), [
            'user_id' => $selectedUser->id,
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'currency' => 'KES',
            'payment_method' => 'manual',
            'status' => 'pending',
        ]);

        $response->assertSessionHasErrors('invoice_id');
        $this->assertDatabaseMissing('payments', [
            'user_id' => $selectedUser->id,
            'invoice_id' => $invoice->id,
        ]);
    }
}
