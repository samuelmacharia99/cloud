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
}
