<?php

namespace Tests\Feature\Reseller;

use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use App\Services\ResellerApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResellerDeveloperPageTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'billing.dev.test';

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
            'password' => Hash::make('secret-password'),
            'reseller_package_id' => $package->id,
            'package_expires_at' => now()->addMonth(),
            'settings' => [
                'branding' => [
                    'company_name' => 'Dev Co',
                    'custom_domain' => self::HOST,
                ],
                'public_api' => [
                    'enabled' => true,
                    'allowed_origins' => [],
                ],
            ],
        ]);
    }

    public function test_developers_page_loads_for_reseller(): void
    {
        $reseller = $this->createReseller();

        $response = $this->actingAs($reseller)->get(route('reseller.developers.index'));

        $response->assertOk()
            ->assertSee('Developers')
            ->assertSee('API credentials')
            ->assertSee(self::HOST);
    }

    public function test_reseller_can_regenerate_api_token_with_password(): void
    {
        $reseller = $this->createReseller();

        $response = $this->actingAs($reseller)->post(route('reseller.developers.token.regenerate'), [
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('reseller.developers.index'));
        $response->assertSessionHas('reseller_api_plain_token');

        $this->assertTrue(app(ResellerApiTokenService::class)->hasActiveToken($reseller->fresh()));
    }

    public function test_bearer_token_authenticates_public_api(): void
    {
        $reseller = $this->createReseller();
        $extension = DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 1000,
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => 1999,
            'enabled' => true,
        ]);

        $token = app(ResellerApiTokenService::class)->regenerate($reseller);

        $this->mock(DomainAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('checkInput')->andReturn([
                'available' => true,
                'full_domain' => 'shop.com',
                'name' => 'shop',
                'extension' => '.com',
                'source' => 'test',
            ]);
        });

        $response = $this->getJson('/api/v1/public/domains/search?q=shop.com', [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('results.0.price', 1999);
    }
}
