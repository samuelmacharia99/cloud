<?php

namespace Tests\Feature\Reseller;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrarConcealmentTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
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

    public function test_reseller_domains_page_does_not_expose_upstream_registrar(): void
    {
        $reseller = $this->createReseller();

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'registrar' => 'SecretUpstreamRegistrar',
            'enabled' => true,
        ]);

        Domain::create([
            'user_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'registrar' => 'SecretUpstreamRegistrar',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $response = $this->actingAs($reseller)->get(route('reseller.domains.index'));

        $response->assertOk();
        $response->assertDontSee('SecretUpstreamRegistrar', false);
    }

    public function test_reseller_domain_pricing_page_does_not_expose_upstream_registrar(): void
    {
        $reseller = $this->createReseller();

        DomainExtension::create([
            'extension' => '.net',
            'description' => 'NET',
            'registrar' => 'HiddenWholesaleProvider',
            'enabled' => true,
        ]);

        $response = $this->actingAs($reseller)->get(route('reseller.domains.pricing'));

        $response->assertOk();
        $response->assertDontSee('HiddenWholesaleProvider', false);
    }
}
