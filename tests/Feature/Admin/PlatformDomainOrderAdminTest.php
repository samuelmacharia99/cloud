<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerDomainOrder;
use App\Models\User;
use App\Services\ResellerDomainOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDomainOrderAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_domain_order_appears_on_admin_domain_orders_index(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'name' => 'directbuy',
            'extension' => '.co.ke',
            'status' => 'pending',
        ]);

        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => 'unpaid',
            'total' => 1500,
        ]);

        $order = app(ResellerDomainOrderService::class)->createForCustomerCheckout(
            $customer,
            $domain,
            $invoice,
            'directbuy',
            '.co.ke',
            1,
            1500,
        );

        $this->assertInstanceOf(ResellerDomainOrder::class, $order);
        $this->assertTrue($order->isPlatformOrder());

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.index'))
            ->assertOk()
            ->assertSee('directbuy.co.ke')
            ->assertSee('Platform (direct)');
    }

    public function test_platform_order_uses_platform_labels_not_reseller_push_wording(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_name' => 'platformlabel',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 1650,
            'retail_amount' => 0,
            'status' => 'pushed',
            'pushed_at' => now(),
        ]);

        $this->assertSame('Prepare for registrar', $order->adminPrepareButtonLabel());
        $this->assertSame('Ready for registrar', $order->statusDisplayLabel());

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.show', $order))
            ->assertOk()
            ->assertSee('Platform direct customer')
            ->assertSee('Ready for registrar')
            ->assertDontSee('Push to admin', false);
    }

    public function test_failed_order_does_not_blame_cosmotown_funds_for_missing_domain_name(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => null]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => null,
            'customer_id' => $customer->id,
            'domain_name' => '911kicks',
            'extension' => '.shop',
            'years' => 1,
            'wholesale_amount' => 480,
            'retail_amount' => 0,
            'status' => 'failed',
            'pushed_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'Domain name is required',
        ]);

        $this->assertSame('Use Push to registrar to retry.', $order->adminRegistrarRetryHint());

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.show', $order))
            ->assertOk()
            ->assertSee('Domain name is required')
            ->assertSee('Use Push to registrar to retry.')
            ->assertDontSee('Top up Cosmotown funds', false);
    }

    public function test_domain_orders_index_does_not_list_renewal_backfills(): void
    {
        $admin = User::factory()->admin()->create();
        $reseller = User::factory()->reseller()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $reseller->id,
            'status' => 'paid',
            'total' => 900,
        ]);

        $backfill = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_name' => 'renewmix',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 900,
            'retail_amount' => 0,
            'status' => 'queued',
            'customer_invoice_id' => $invoice->id,
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_type' => 'Domain',
            'description' => 'Renew renewmix.com (1 year)',
            'quantity' => 1,
            'unit_price' => 900,
            'amount' => 900,
            'custom_options' => [
                'type' => 'domain_renewal',
                'renewal_order_id' => 7,
                'domain_order_id' => $backfill->id,
            ],
        ]);

        ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_name' => 'freshreg',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 1200,
            'retail_amount' => 0,
            'status' => 'queued',
            'queued_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.domain-orders.index'))
            ->assertOk()
            ->assertSee('freshreg.com')
            ->assertDontSee('renewmix.com')
            ->assertSee('Domain Renewals');
    }
}
