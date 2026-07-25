<?php

namespace Tests\Feature\Customer;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Checkout\EmailHostingCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EmailHostingDomainCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_checkout_requires_domain_mode(): void
    {
        $customer = User::factory()->customer()->create();
        $plan = Product::factory()->emailHosting()->create(['is_active' => true]);

        $cart = [
            'email-1' => [
                'type' => 'product',
                'product_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ],
        ];

        $request = Request::create('/checkout', 'POST', []);
        $request->setUserResolver(fn () => $customer);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(EmailHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);
    }

    public function test_email_checkout_existing_domain_builds_mailcow_meta_and_links_owned_domain(): void
    {
        $customer = User::factory()->customer()->create();
        Node::factory()->mailcow()->create();
        $plan = Product::factory()->emailHosting()->create(['is_active' => true]);
        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'mailme',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-123',
        ]);

        $cart = [
            'email-1' => [
                'type' => 'product',
                'product_id' => $plan->id,
                'billing_cycle' => 'monthly',
            ],
        ];

        $request = Request::create('/checkout', 'POST', [
            'email_domain_mode' => ['email-1' => 'existing'],
            'email_domain_fqdn' => ['email-1' => 'mailme.com'],
        ]);
        $request->setUserResolver(fn () => $customer);

        app(EmailHostingCheckoutService::class)->validateCheckoutRequest($request, $cart);

        $invoice = Invoice::factory()->create(['user_id' => $customer->id]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'invoice_id' => $invoice->id]);

        $context = app(EmailHostingCheckoutService::class)->buildEmailHostingContext(
            $request,
            'email-1',
            $customer,
            $plan,
            $invoice,
            $order,
            $cart,
        );

        $this->assertSame('mailme.com', $context['service_meta']['mailcow_domain']);
        $this->assertSame($domain->id, $context['service_meta']['domain_id']);
        $this->assertTrue($context['service_meta']['cloudflare_dns']);
        $this->assertSame('existing', $context['service_meta']['email_domain_mode']);
    }

    public function test_customer_can_open_email_inboxes_hub(): void
    {
        $customer = User::factory()->customer()->create();
        $plan = Product::factory()->emailHosting()->create(['is_active' => true]);
        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $plan->id,
            'status' => ServiceStatus::Active,
            'name' => 'Mail for example.com',
            'service_meta' => ['mailcow_domain' => 'example.com'],
            'provisioning_driver_key' => 'mailcow',
        ]);

        $this->actingAs($customer)
            ->get(route('customer.email.inboxes'))
            ->assertOk()
            ->assertSee('Inboxes')
            ->assertSee('example.com');
    }

    public function test_email_menu_order_page_still_lists_plans(): void
    {
        $customer = User::factory()->customer()->create();
        Product::factory()->emailHosting()->create([
            'name' => 'Mailbox Basic',
            'is_active' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.email-hosting'))
            ->assertOk()
            ->assertSee('Mailbox Basic');
    }
}
