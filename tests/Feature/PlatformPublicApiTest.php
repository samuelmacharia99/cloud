<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use App\Services\PlatformApiTokenService;
use App\Services\PlatformPublicApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformPublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://platform.example.test']);
        Setting::setValue('site_url', 'https://platform.example.test');
        Setting::setValue('public_website_api_enabled', '1');
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'password' => Hash::make('secret-password'),
        ]);
    }

    private function seedDomain(string $extension, float $retail): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => $extension,
            'description' => 'Test',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => $retail,
            'enabled' => true,
        ]);

        return $ext;
    }

    public function test_platform_domain_search_on_main_host(): void
    {
        $this->seedDomain('.com', 1999);

        $this->mock(DomainAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('checkInput')->andReturn([
                'available' => true,
                'full_domain' => 'shop.com',
                'name' => 'shop',
                'extension' => '.com',
                'source' => 'test',
            ]);
        });

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->getJson('https://platform.example.test/api/v1/public/domains/search?q=shop.com');

        $response->assertOk()
            ->assertJsonPath('results.0.price', 1999)
            ->assertJsonPath('checkout_url', 'https://platform.example.test/domain-checkout');
    }

    public function test_platform_services_lists_active_products(): void
    {
        Product::create([
            'name' => 'Starter Hosting',
            'slug' => 'starter-hosting',
            'type' => 'shared_hosting',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'is_active' => true,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->getJson('https://platform.example.test/api/v1/public/services');

        $response->assertOk()
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('services.0.name', 'Starter Hosting');
    }

    public function test_platform_api_works_on_site_url_host_when_app_url_differs(): void
    {
        config(['app.url' => 'https://talksasa.com']);
        Setting::setValue('site_url', 'https://servers.talksasa.com');

        Product::create([
            'name' => 'Cloud VPS',
            'slug' => 'cloud-vps',
            'type' => 'vps',
            'monthly_price' => 2500,
            'is_active' => true,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'servers.talksasa.com'])
            ->getJson('https://servers.talksasa.com/api/v1/public/services');

        $response->assertOk()
            ->assertJsonPath('services.0.name', 'Cloud VPS');

        $this->assertSame(
            'https://servers.talksasa.com/api/v1/public',
            app(PlatformPublicApiService::class)->apiBaseUrl(),
        );
    }

    public function test_admin_developers_page_and_token(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get(route('admin.developers.index'))->assertOk()->assertSee('Developers');

        $response = $this->actingAs($admin)->post(route('admin.developers.token.regenerate'), [
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.developers.index'));
        $this->assertTrue(app(PlatformApiTokenService::class)->hasActiveToken());
    }

    public function test_platform_cart_prepares_checkout_session(): void
    {
        $this->seedDomain('.com', 1500);

        $this->mock(DomainAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('checkInput')->andReturn([
                'available' => true,
                'full_domain' => 'buy.com',
                'name' => 'buy',
                'extension' => '.com',
                'source' => 'test',
            ]);
        });

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->postJson('https://platform.example.test/api/v1/public/cart', [
                'items' => [['type' => 'domain', 'full_domain' => 'buy.com', 'years' => 1]],
            ]);

        $response->assertOk()
            ->assertJsonPath('checkout_url', 'https://platform.example.test/domain-checkout');

        $this->assertCount(1, session(CheckoutController::CART_SESSION_KEY, []));
        $this->assertNull(session('registration_reseller_id'));
    }

    public function test_api_disabled_returns_403(): void
    {
        Setting::setValue('public_website_api_enabled', '0');

        $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->getJson('https://platform.example.test/api/v1/public/services')
            ->assertForbidden();
    }
}
