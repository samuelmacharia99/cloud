<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\ResellerPackage;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function createResellerWithPackage(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test package',
            'billing_cycle' => 'monthly',
            'storage_space' => 100,
            'max_users' => 100,
            'price' => 1000,
            'active' => true,
        ]);

        return User::factory()->reseller()->create([
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
        ]);
    }

    public function test_reseller_can_delete_own_domain(): void
    {
        $reseller = $this->createResellerWithPackage();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $response = $this->actingAs($reseller)
            ->delete(route('reseller.domains.destroy', $domain));

        $response->assertRedirect(route('reseller.domains.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_reseller_can_delete_managed_customer_domain(): void
    {
        $reseller = $this->createResellerWithPackage();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        Service::factory()->create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
        ]);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => 'client',
            'extension' => '.co.ke',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->delete(route('reseller.domains.destroy', $domain))
            ->assertRedirect(route('reseller.domains.index'));

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_reseller_cannot_delete_unrelated_domain(): void
    {
        $reseller = $this->createResellerWithPackage();
        $otherUser = User::factory()->create();

        $domain = Domain::create([
            'user_id' => $otherUser->id,
            'name' => 'other',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->delete(route('reseller.domains.destroy', $domain))
            ->assertForbidden();

        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
    }
}
