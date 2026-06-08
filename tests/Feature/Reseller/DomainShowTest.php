<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainShowTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
            'description' => 'Test',
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

    public function test_reseller_can_view_domain_detail_page(): void
    {
        $reseller = $this->createReseller();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'nameserver_1' => 'ns1.example.com',
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.show', $domain))
            ->assertOk()
            ->assertSee('example.com')
            ->assertSee('ns1.example.com');
    }

    public function test_reseller_can_update_nameservers(): void
    {
        $reseller = $this->createReseller();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->put(route('reseller.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.new.com',
                'nameserver_2' => 'ns2.new.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.new.com',
            'nameserver_2' => 'ns2.new.com',
        ]);
    }
}
