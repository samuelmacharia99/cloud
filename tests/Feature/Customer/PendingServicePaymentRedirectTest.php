<?php

namespace Tests\Feature\Customer;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingServicePaymentRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_service_manage_redirects_to_unpaid_invoice(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'provisioning_driver_key' => 'container',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.show', $service))
            ->assertRedirect(route('customer.payment.select-method', $invoice));
    }

    public function test_pending_service_container_url_redirects_to_unpaid_invoice(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'provisioning_driver_key' => 'container',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.container.show', $service))
            ->assertRedirect(route('customer.payment.select-method', $invoice));
    }

    public function test_pending_service_with_paid_invoice_is_not_redirected(): void
    {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create(['type' => 'shared_hosting']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'paid',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.services.show', $service))
            ->assertOk();
    }
}
