<?php

namespace Tests\Feature\Reseller;

use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerLandingPageTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'billing.acme.test';

    private function createReseller(array $branding = []): User
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
            'settings' => [
                'branding' => array_merge([
                    'company_name' => 'Acme Hosting',
                    'tagline' => 'Reliable hosting',
                    'custom_domain' => self::HOST,
                    'primary_color' => '#1a4b8c',
                    'landing_enabled' => true,
                    'landing_template' => 'legacy',
                    'landing_show_domains' => true,
                    'landing_show_hosting' => true,
                ], $branding),
            ],
        ]);
    }

    private function seedRetailExtension(User $reseller, string $extension = '.ke', float $retail = 1200): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => $extension,
            'description' => 'Kenya',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 800,
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => $retail,
            'enabled' => true,
        ]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'retail_price' => $retail,
            'enabled' => true,
        ]);

        return $ext;
    }

    public function test_platform_home_still_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_reseller_host_without_landing_redirects_to_login(): void
    {
        $this->createReseller(['landing_enabled' => false]);

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/')
            ->assertRedirect(route('login'));
    }

    public function test_reseller_host_with_landing_renders_legacy_storefront(): void
    {
        $reseller = $this->createReseller();
        $this->seedRetailExtension($reseller);

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Starter Web',
            'description' => 'Shared hosting starter',
            'type' => 'shared_hosting',
            'direct_admin_package_name' => 'starter',
            'monthly_price' => 999,
            'yearly_price' => 9990,
            'setup_fee' => 0,
            'is_active' => true,
            'features' => ['10 GB SSD', 'Free SSL'],
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/');

        $response->assertOk()
            ->assertSee('Acme Hosting')
            ->assertSee('Find your new domain name')
            ->assertSee('.ke')
            ->assertSee('Starter Web')
            ->assertSee('Web Hosting')
            ->assertSee('Client Area');
    }

    public function test_storefront_domain_search_works_without_public_api(): void
    {
        $reseller = $this->createReseller();
        $this->seedRetailExtension($reseller, '.com', 1500);

        $this->mock(DomainAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('checkInput')->andReturn([
                'name' => 'example',
                'extension' => '.com',
                'full_domain' => 'example.com',
                'available' => true,
            ]);
        });

        $response = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->getJson('https://'.self::HOST.'/store/domains/search?q=example');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('results.0.full_domain', 'example.com')
            ->assertJsonPath('results.0.available', true);
    }

    public function test_reseller_can_save_landing_settings_from_branding_tab(): void
    {
        $reseller = $this->createReseller(['landing_enabled' => false]);

        $response = $this->actingAs($reseller)->post(route('reseller.settings.branding.update'), [
            'company_name' => 'Acme Hosting',
            'tagline' => 'Reliable hosting',
            'custom_domain' => self::HOST,
            'primary_color' => '#1a4b8c',
            'footer_text' => '',
            'support_email' => 'hello@acme.test',
            'support_phone' => '',
            'landing_enabled' => '1',
            'landing_template' => 'legacy',
            'landing_hero_headline' => 'Find your domain',
            'landing_hero_subtext' => 'Fast checkout',
            'landing_show_domains' => '1',
            'landing_show_hosting' => '1',
            'public_api_enabled' => '0',
            'public_api_allowed_origins' => '',
        ]);

        $response->assertRedirect();

        $reseller->refresh();
        $this->assertTrue((bool) ($reseller->settings['branding']['landing_enabled'] ?? false));
        $this->assertSame('legacy', $reseller->settings['branding']['landing_template'] ?? null);
        $this->assertSame('Find your domain', $reseller->settings['branding']['landing_hero_headline'] ?? null);
    }
}
