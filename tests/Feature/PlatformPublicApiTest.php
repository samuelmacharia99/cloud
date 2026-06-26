<?php

namespace Tests\Feature;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Product;
use App\Models\ResellerPackage;
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
            ->assertJsonPath('services.0.name', 'Starter Hosting')
            ->assertJsonMissingPath('services.0.configuration');
    }

    public function test_platform_services_include_vps_configuration(): void
    {
        Product::create([
            'name' => 'Cloud VPS',
            'slug' => 'cloud-vps',
            'type' => 'vps',
            'monthly_price' => 1000,
            'yearly_price' => 12000,
            'setup_fee' => 50,
            'is_active' => true,
            'resource_limits' => [
                'cpu_cores' => 2,
                'ram_gb' => 4,
                'storage_gb' => 80,
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'United States',
                        'monthly_surcharge' => 300,
                        'yearly_surcharge' => 3600,
                        'setup_surcharge' => 0,
                    ],
                ],
            ],
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->getJson('https://platform.example.test/api/v1/public/services');

        $response->assertOk()
            ->assertJsonPath('services.0.configuration.specs.cpu_cores', 2)
            ->assertJsonPath('services.0.configuration.locations.0.key', 'usa')
            ->assertJsonPath('services.0.configuration.locations.0.prices.monthly', 1300);
    }

    public function test_platform_cart_accepts_configured_vps_item(): void
    {
        $product = Product::create([
            'name' => 'Cloud VPS',
            'slug' => 'cloud-vps-cart',
            'type' => 'vps',
            'monthly_price' => 1000,
            'yearly_price' => 12000,
            'setup_fee' => 50,
            'is_active' => true,
            'resource_limits' => [
                'cpu_cores' => 2,
                'ram_gb' => 4,
                'storage_gb' => 80,
                'locations' => [
                    [
                        'key' => 'usa',
                        'name' => 'United States',
                        'monthly_surcharge' => 300,
                        'yearly_surcharge' => 3600,
                        'setup_surcharge' => 0,
                    ],
                ],
            ],
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->postJson('https://platform.example.test/api/v1/public/cart', [
                'items' => [[
                    'type' => 'service',
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'location_key' => 'usa',
                    'ip_count' => 1,
                    'operating_system' => 'ubuntu-24.04',
                ]],
            ]);

        $response->assertOk();

        $cart = session(CheckoutController::CART_SESSION_KEY, []);
        $this->assertCount(1, $cart);
        $item = array_values($cart)[0];
        $this->assertSame('product', $item['type']);
        $this->assertSame('usa', $item['location_key']);
        $this->assertSame('ubuntu-24.04', $item['operating_system']);
    }

    public function test_platform_cart_rejects_vps_without_operating_system(): void
    {
        $product = Product::create([
            'name' => 'Cloud VPS',
            'slug' => 'cloud-vps-invalid',
            'type' => 'vps',
            'monthly_price' => 1000,
            'yearly_price' => 12000,
            'is_active' => true,
            'resource_limits' => [
                'cpu_cores' => 2,
                'locations' => [
                    ['key' => 'usa', 'name' => 'United States', 'monthly_surcharge' => 0],
                ],
            ],
        ]);

        $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->postJson('https://platform.example.test/api/v1/public/cart', [
                'items' => [[
                    'type' => 'service',
                    'product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                    'location_key' => 'usa',
                    'ip_count' => 1,
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_platform_reseller_packages_lists_active_plans(): void
    {
        ResellerPackage::create([
            'name' => 'Starter Reseller',
            'description' => 'Launch your brand',
            'billing_cycle' => 'monthly',
            'storage_space' => 500,
            'max_services' => 50,
            'disk_pool_gb' => 500,
            'max_users' => 100,
            'price' => 4999,
            'active' => true,
        ]);

        $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->getJson('https://platform.example.test/api/v1/public/reseller-packages?cycle=monthly')
            ->assertOk()
            ->assertJsonPath('packages.0.name', 'Starter Reseller')
            ->assertJsonPath('packages.0.max_users', 100);
    }

    public function test_platform_cart_accepts_reseller_package(): void
    {
        $package = ResellerPackage::create([
            'name' => 'Growth Reseller',
            'billing_cycle' => 'monthly',
            'storage_space' => 1000,
            'max_services' => 100,
            'disk_pool_gb' => 1000,
            'max_users' => 250,
            'price' => 9999,
            'active' => true,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->postJson('https://platform.example.test/api/v1/public/cart', [
                'items' => [[
                    'type' => 'reseller_package',
                    'reseller_package_id' => $package->id,
                ]],
            ]);

        $response->assertOk();

        $cart = session(CheckoutController::CART_SESSION_KEY, []);
        $this->assertCount(1, $cart);
        $item = array_values($cart)[0];
        $this->assertSame('reseller_package', $item['type']);
        $this->assertSame($package->id, $item['reseller_package_id']);
    }

    public function test_platform_api_works_on_site_url_host_when_app_url_differs(): void
    {
        config(['app.url' => 'https://servers.talksasa.com']);
        Setting::setValue('site_url', 'https://talksasa.com');

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

    public function test_admin_can_reveal_existing_token_with_password(): void
    {
        $admin = $this->createAdmin();
        $service = app(PlatformApiTokenService::class);
        $plainText = $service->regenerate($admin);

        $response = $this->actingAs($admin)->post(route('admin.developers.token.reveal'), [
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.developers.index'));
        $response->assertSessionHas('platform_api_plain_token', $plainText);
    }

    public function test_platform_extensions_include_transfer_price(): void
    {
        $ext = $this->seedDomain('.com', 1999);
        $ext->update(['transfer_price' => 1499]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->getJson('https://platform.example.test/api/v1/public/domains/extensions?period=1');

        $response->assertOk()
            ->assertJsonPath('extensions.0.extension', '.com')
            ->assertJsonPath('extensions.0.price', 1999)
            ->assertJsonPath('extensions.0.transfer_price', 1499);
    }

    public function test_platform_cart_accepts_domain_transfer(): void
    {
        $ext = $this->seedDomain('.com', 1999);
        $ext->update(['transfer_price' => 1499]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'platform.example.test'])
            ->postJson('https://platform.example.test/api/v1/public/cart', [
                'items' => [[
                    'type' => 'domain_transfer',
                    'full_domain' => 'legacy.com',
                    'epp_code' => 'AUTH-12345',
                    'old_registrar' => 'Old Registrar',
                ]],
            ]);

        $response->assertOk()
            ->assertJsonPath('item_count', 1);

        $cart = session(CheckoutController::CART_SESSION_KEY, []);
        $this->assertCount(1, $cart);
        $item = array_values($cart)[0];
        $this->assertSame('domain_transfer', $item['type']);
        $this->assertSame('legacy', $item['domain']);
        $this->assertSame('.com', $item['extension']);
        $this->assertSame(1499.0, (float) $item['price']);
        $this->assertSame('AUTH-12345', $item['epp_code']);
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
