<?php

namespace Tests\Feature\Reseller;

use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDomainOrdersIndexTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 50,
            'max_users' => 10,
            'price' => 500,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_index_lists_only_managed_customer_domain_orders(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_name' => 'customer-brand',
            'extension' => '.co.ke',
            'years' => 1,
            'wholesale_amount' => 800,
            'retail_amount' => 700,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $reseller->id,
            'domain_name' => 'reseller-own',
            'extension' => '.com',
            'years' => 1,
            'wholesale_amount' => 500,
            'retail_amount' => 0,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $response = $this->actingAs($reseller)->get(route('reseller.domain-orders.index'));

        $response->assertOk();
        $response->assertSee('Domain Orders');
        $response->assertSee('customer-brand.co.ke');
        $response->assertSee($customer->name);
        $response->assertDontSee('reseller-own.com');
    }

    public function test_index_can_filter_by_customer(): void
    {
        $reseller = $this->reseller();
        $customerA = User::factory()->customer()->create(['reseller_id' => $reseller->id, 'name' => 'Alice Customer']);
        $customerB = User::factory()->customer()->create(['reseller_id' => $reseller->id, 'name' => 'Bob Customer']);

        ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customerA->id,
            'domain_name' => 'alice',
            'extension' => '.test',
            'years' => 1,
            'wholesale_amount' => 100,
            'retail_amount' => 50,
            'status' => 'completed',
        ]);

        ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customerB->id,
            'domain_name' => 'bob',
            'extension' => '.test',
            'years' => 1,
            'wholesale_amount' => 100,
            'retail_amount' => 50,
            'status' => 'completed',
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.domain-orders.index', ['customer_id' => $customerA->id]))
            ->assertOk()
            ->assertSee('alice.test')
            ->assertSee('Alice Customer')
            ->assertDontSee('bob.test');
    }
}
