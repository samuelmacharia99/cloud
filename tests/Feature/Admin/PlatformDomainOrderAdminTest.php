<?php

namespace Tests\Feature\Admin;

use App\Models\Domain;
use App\Models\Invoice;
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
}
