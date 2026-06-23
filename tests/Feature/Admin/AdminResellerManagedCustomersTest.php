<?php

namespace Tests\Feature\Admin;

use App\Models\DomainExtension;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminResellerManagedCustomersTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Pkg '.uniqid(),
            'description' => 'Test',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'name' => 'Reseller Co',
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_admin_reseller_domains_tab_lists_transferred_customer_without_services(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = $this->createReseller();
        $customer = User::factory()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Transferred Customer',
            'email' => 'transferred@example.com',
        ]);

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'domains']));

        $response->assertOk();
        $response->assertSee('Transferred Customer');
        $response->assertSee('transferred@example.com');
        $response->assertSee('value="'.$customer->id.'"', false);
    }

    public function test_admin_can_add_domain_to_transferred_customer_without_services(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.resellers.add-domain', $reseller), [
                'owner_id' => $customer->id,
                'domain_name' => 'example',
                'extension' => '.com',
                'status' => 'active',
                'expires_at' => now()->addYear()->format('Y-m-d'),
            ])
            ->assertRedirect(route('admin.resellers.show', ['user' => $reseller, 'tab' => 'domains']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('domains', [
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
        ]);
    }
}
