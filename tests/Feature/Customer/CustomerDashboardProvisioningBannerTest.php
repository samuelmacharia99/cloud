<?php

namespace Tests\Feature\Customer;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDashboardProvisioningBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_service_with_unpaid_invoice_does_not_show_provisioning_banner(): void
    {
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $pendingProduct = Product::factory()->create(['name' => 'Ghost Pending Plan']);
        $activeProduct = Product::factory()->create(['name' => 'Node Droplet1']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => InvoiceStatus::Unpaid,
            'total' => 1000,
        ]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $pendingProduct->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceStatus::Pending,
            'name' => 'Hidden Pending App',
        ]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $activeProduct->id,
            'status' => ServiceStatus::Active,
            'name' => 'Live App',
        ]);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('service(s) provisioning', false)
            ->assertDontSee('awaiting setup', false)
            ->assertSee('Node Droplet1');
    }

    public function test_provisioning_banner_only_for_provisioning_status(): void
    {
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $product = Product::factory()->create(['name' => 'Deploying Plan']);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Provisioning,
            'name' => 'Spinning Up Node',
        ]);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('service(s) provisioning', false)
            ->assertSee('Spinning Up Node');
    }

    public function test_paid_pending_shows_awaiting_setup_not_provisioning(): void
    {
        $customer = User::factory()->customer()->create(['reseller_id' => null]);
        $product = Product::factory()->create(['name' => 'Queued Plan']);
        $invoice = Invoice::factory()->create([
            'user_id' => $customer->id,
            'status' => InvoiceStatus::Paid,
            'total' => 1000,
        ]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
            'status' => ServiceStatus::Pending,
            'name' => 'Queued Setup',
        ]);

        $this->actingAs($customer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('service(s) provisioning', false)
            ->assertSee('awaiting setup', false)
            ->assertSee('Queued Setup');
    }
}
