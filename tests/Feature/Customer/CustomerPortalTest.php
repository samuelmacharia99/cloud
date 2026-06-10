<?php

namespace Tests\Feature\Customer;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->customer()->create();
    }

    public function test_customer_can_view_credits_index(): void
    {
        $customer = $this->customer();
        CreditService::createManualCredit($customer, 500, 'Test credit');

        $this->actingAs($customer)
            ->get(route('customer.credits.index'))
            ->assertOk()
            ->assertSee('Account Credits')
            ->assertSee('500');
    }

    public function test_invoice_auto_applies_credits_on_payment_page(): void
    {
        $customer = $this->customer();
        CreditService::createManualCredit($customer, 1000, 'Test credit');

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 500,
            'subtotal' => 500,
            'tax' => 0,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.payment.select-method', $invoice))
            ->assertRedirect(route('customer.payment.success', $invoice));
    }

    public function test_checkout_can_apply_credits_when_placing_order(): void
    {
        $customer = $this->customer();
        CreditService::createManualCredit($customer, 1000, 'Checkout credit');

        $product = \App\Models\Product::factory()->create([
            'monthly_price' => 500,
            'is_active' => true,
        ]);

        session([\App\Http\Controllers\Customer\CheckoutController::CART_SESSION_KEY => [
            'item-1' => [
                'type' => 'product',
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
            ],
        ]]);

        $this->actingAs($customer)
            ->post(route('customer.checkout.process'), [
                'agree_terms' => '1',
                'apply_credits' => '1',
            ])
            ->assertRedirect();

        $invoice = Invoice::where('user_id', $customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertTrue($invoice->fresh()->status->value === 'paid' || $invoice->getAppliedCredits() > 0);
    }

    public function test_customer_can_apply_partial_credits(): void
    {
        $customer = $this->customer();
        CreditService::createManualCredit($customer, 300, 'Partial credit');

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 800,
            'subtotal' => 800,
            'tax' => 0,
        ]);

        $this->actingAs($customer)
            ->post(route('customer.payment.apply-credits', $invoice))
            ->assertRedirect(route('customer.payment.select-method', $invoice));

        $invoice->refresh();
        $this->assertEquals(300, $invoice->getAppliedCredits());
        $this->assertEquals(500, $invoice->getAmountRemaining());
    }

    public function test_customer_can_cancel_pending_unpaid_order(): void
    {
        $customer = $this->customer();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($customer)
            ->post(route('customer.orders.cancel', $order))
            ->assertRedirect(route('customer.orders.index'));

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals('cancelled', $invoice->fresh()->status->value);
    }

    public function test_customer_cannot_cancel_paid_order(): void
    {
        $customer = $this->customer();
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($customer)
            ->post(route('customer.orders.cancel', $order))
            ->assertForbidden();
    }

    public function test_customer_can_filter_tickets(): void
    {
        $customer = $this->customer();

        Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Billing issue open',
            'description' => 'Need help',
            'status' => 'open',
            'priority' => 'high',
        ]);

        Ticket::create([
            'user_id' => $customer->id,
            'title' => 'Closed ticket',
            'description' => 'Resolved',
            'status' => 'closed',
            'priority' => 'low',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.tickets.index', ['status' => 'open']))
            ->assertOk()
            ->assertSee('Billing issue open')
            ->assertDontSee('Closed ticket');
    }

    public function test_customer_can_list_own_payments(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('customer.payments.index'))
            ->assertOk()
            ->assertSee('My Payments');
    }

    public function test_customer_can_view_bank_transfer_form(): void
    {
        $customer = $this->customer();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 1000,
            'subtotal' => 1000,
            'tax' => 0,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.payment.bank-transfer-form', $invoice))
            ->assertOk()
            ->assertSee('Bank Transfer');
    }

    public function test_service_cancellation_invokes_provisioning_terminate(): void
    {
        $customer = $this->customer();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'status' => 'active',
        ]);

        $this->mock(ProvisioningService::class, function ($mock) use ($service) {
            $mock->shouldReceive('terminate')
                ->once()
                ->with(\Mockery::on(fn ($s) => $s->id === $service->id));
        });

        $this->actingAs($customer)
            ->post(route('customer.services.cancel', $service), [
                'reason' => 'No longer needed for this project',
            ])
            ->assertRedirect(route('customer.services.index'));
    }
}
