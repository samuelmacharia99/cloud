<?php

namespace Tests\Feature\Customer;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paying for application hosting used to end on a page that pointed at the
 * dashboard; the service was provisioning with nobody watching. The success
 * page now opens the live deploy console for every container service on
 * the invoice.
 */
class PaymentSuccessDeployingLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_success_page_links_to_the_deploying_console_for_container_services(): void
    {
        $customer = User::factory()->customer()->create();
        $invoice = Invoice::factory()->create(['user_id' => $customer->id, 'status' => 'paid']);
        $container = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'invoice_id' => $invoice->id,
            'status' => 'provisioning',
            'name' => 'Shop',
            'provisioning_driver_key' => 'container',
        ]);
        $shared = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => Product::factory()->create(['type' => 'shared_hosting'])->id,
            'invoice_id' => $invoice->id,
            'status' => 'pending',
        ]);

        $this->actingAs($customer)->get(route('customer.payment.success', $invoice))
            ->assertOk()
            ->assertSee('Watch Shop deploy')
            ->assertSee(route('customer.services.deploying', $container), false)
            ->assertDontSee(route('customer.services.deploying', $shared), false);
    }

    #[Test]
    public function another_customer_cannot_open_the_success_page(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->customer()->create()->id, 'status' => 'paid']);

        $this->actingAs(User::factory()->customer()->create())
            ->get(route('customer.payment.success', $invoice))
            ->assertForbidden();
    }
}
