<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Product;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerDomainAssignmentService;
use App\Support\ResellerCartContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A domain registered at wholesale sits on the reseller's own account and is
 * invisible to the customer it was bought for. The reseller can now see
 * those, hand them over in bulk, and cannot buy one that way by accident.
 */
class ResellerDomainAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reseller_sees_their_own_domains_with_the_matching_customer_preselected(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id, 'name' => 'Acme Ltd']);
        $this->hostingService($reseller, $customer, 'acme.co.ke');
        $mine = $this->ownDomain($reseller, 'acme', '.co.ke');
        $this->ownDomain($reseller, 'unrelated', '.com');

        $rows = app(ResellerDomainAssignmentService::class)->unassigned($reseller);
        $this->assertCount(2, $rows);
        $this->assertSame($customer->id, $rows->firstWhere('domain.id', $mine->id)['suggested']?->id);
        $this->assertNull($rows->firstWhere('domain.name', 'unrelated')['suggested']);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.index'))
            ->assertOk()
            ->assertSee('Domains on your own account')
            ->assertSee('acme.co.ke')
            ->assertSee('Assign to customers')
            ->assertSee('name="assignments['.$mine->id.']"', false);
    }

    public function test_assigning_moves_the_domain_into_the_customers_portal(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $mine = $this->ownDomain($reseller, 'acme', '.co.ke');
        $keep = $this->ownDomain($reseller, 'keepme', '.com');

        $this->actingAs($customer)->get(route('customer.domains.index'))->assertOk()->assertDontSee('acme.co.ke');

        $this->actingAs($reseller)
            ->post(route('reseller.domains.assign'), [
                'assignments' => [$mine->id => $customer->id, $keep->id => ''],
            ])
            ->assertRedirect(route('reseller.domains.index'))
            ->assertSessionHas('success');

        $this->assertSame($customer->id, (int) $mine->fresh()->user_id);
        $this->assertSame($reseller->id, (int) $mine->fresh()->reseller_id);
        $this->assertSame($reseller->id, (int) $keep->fresh()->user_id);

        $this->actingAs($customer)->get(route('customer.domains.index'))->assertOk()->assertSee('acme.co.ke');
        $this->actingAs($reseller)->get(route('reseller.domains.index'))->assertOk()->assertDontSee('name="assignments['.$mine->id.']"', false);
    }

    public function test_a_domain_cannot_be_assigned_to_someone_elses_customer_or_from_someone_elses_account(): void
    {
        $reseller = $this->reseller();
        $other = $this->reseller();
        $stranger = User::factory()->customer()->create(['reseller_id' => $other->id]);
        $mine = $this->ownDomain($reseller, 'acme', '.co.ke');
        $theirs = $this->ownDomain($other, 'theirs', '.com');
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.assign'), [
                'assignments' => [$mine->id => $stranger->id, $theirs->id => $customer->id],
            ])
            ->assertRedirect(route('reseller.domains.index'))
            ->assertSessionHas('error');

        $this->assertSame($reseller->id, (int) $mine->fresh()->user_id);
        $this->assertSame($other->id, (int) $theirs->fresh()->user_id);
    }

    public function test_a_wholesale_registration_needs_the_reseller_to_confirm_it_is_for_their_own_use(): void
    {
        $reseller = $this->reseller();
        $extension = DomainExtension::create(['extension' => '.test', 'enabled' => true]);
        DomainPricing::create(['domain_extension_id' => $extension->id, 'tier' => 'wholesale', 'period_years' => 1, 'price' => 500, 'renewal_price' => 500, 'enabled' => true]);

        $payload = ['domain' => 'brandnew', 'extension' => '.test', 'years' => 1, 'price' => 500];

        $this->actingAs($reseller)
            ->postJson(route('reseller.cart.add'), $payload)
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'confirm_own_use']);

        $this->assertEmpty(session('reseller_cart', []));
    }

    public function test_a_customer_mode_registration_needs_no_confirmation(): void
    {
        $reseller = $this->reseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $extension = DomainExtension::create(['extension' => '.test', 'enabled' => true]);
        DomainPricing::create(['domain_extension_id' => $extension->id, 'tier' => 'wholesale', 'period_years' => 1, 'price' => 500, 'renewal_price' => 500, 'enabled' => true]);
        ResellerCartContext::setCustomer($customer->id);

        $retail = $this->actingAs($reseller)
            ->getJson(route('reseller.domains.pricing.api', ['extension' => '.test']).'?period=1&retail=1')
            ->assertOk()
            ->json('price');

        $this->actingAs($reseller)
            ->postJson(route('reseller.cart.add'), ['domain' => 'forclient', 'extension' => '.test', 'years' => 1, 'price' => $retail])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    private function reseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 0,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    private function ownDomain(User $reseller, string $name, string $extension): Domain
    {
        return Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => $name,
            'extension' => $extension,
            'status' => 'active',
            'type' => 'registration',
        ]);
    }

    private function hostingService(User $reseller, User $customer, string $hostname): Service
    {
        $product = Product::factory()->create(['type' => 'shared_hosting', 'provisioning_driver_key' => 'directadmin']);

        return Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'directadmin',
            'status' => 'active',
            'name' => $hostname,
            'service_meta' => ['username' => 'acme', 'domain' => $hostname],
        ]);
    }
}
