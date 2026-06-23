<?php

namespace Tests\Feature\Reseller;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\Setting;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ResellerPublicApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'billing.acme.test';

    private function createReseller(array $settings = []): User
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
            'settings' => array_merge([
                'branding' => [
                    'company_name' => 'Acme Hosting',
                    'custom_domain' => self::HOST,
                ],
                'public_api' => [
                    'enabled' => true,
                    'allowed_origins' => ['https://www.acme.test'],
                ],
            ], $settings),
        ]);
    }

    private function seedExtension(string $extension, float $wholesale, float $retail): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => $extension,
            'description' => strtoupper(ltrim($extension, '.')),
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => $wholesale,
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

    private function enableResellerRetail(User $reseller, DomainExtension $extension, float $retail): void
    {
        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'retail_price' => $retail,
            'enabled' => true,
        ]);
    }

    private function onResellerHost(string $path, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->getJson('https://'.self::HOST.$path, $headers);
    }

    private function postOnResellerHost(string $path, array $data = [], array $headers = []): TestResponse
    {
        return $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->postJson('https://'.self::HOST.$path, $data, $headers);
    }

    public function test_api_returns_404_on_platform_host(): void
    {
        $this->createReseller();
        config(['app.url' => 'https://platform.example.test']);
        Setting::setValue('site_url', 'https://platform.example.test');

        $response = $this->withServerVariables(['HTTP_HOST' => 'unknown.example.test'])
            ->getJson('https://unknown.example.test/api/v1/public/domains/search?q=example');

        $response->assertNotFound()
            ->assertJson(['success' => false]);
    }

    public function test_api_returns_403_when_not_enabled(): void
    {
        $this->createReseller([
            'public_api' => ['enabled' => false],
        ]);

        $response = $this->onResellerHost('/api/v1/public/domains/search?q=example');

        $response->assertForbidden()
            ->assertJson(['success' => false]);
    }

    public function test_domain_search_returns_only_enabled_reseller_tlds(): void
    {
        $reseller = $this->createReseller();
        $com = $this->seedExtension('.com', 1000, 1500);
        $coKe = $this->seedExtension('.co.ke', 800, 1200);
        $this->enableResellerRetail($reseller, $com, 1999);
        $this->enableResellerRetail($reseller, $coKe, 1599);

        $this->mock(DomainAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('checkInput')
                ->andReturnUsing(function (string $input, ?string $extension = null) {
                    $ext = $extension ?? '.com';

                    return [
                        'available' => $ext === '.com',
                        'full_domain' => $input.$ext,
                        'name' => $input,
                        'extension' => $ext,
                        'source' => 'test',
                    ];
                });
        });

        $response = $this->onResellerHost('/api/v1/public/domains/search?q=acme');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('currency', 'KES')
            ->assertJsonCount(2, 'results');

        $extensions = collect($response->json('results'))->pluck('extension')->all();
        $this->assertEquals(['.com', '.co.ke'], $extensions);

        $comResult = collect($response->json('results'))->firstWhere('extension', '.com');
        $this->assertTrue($comResult['available']);
        $this->assertSame(1999.0, (float) $comResult['price']);
        $this->assertStringContainsString('/checkout', $comResult['checkout_url']);
        $this->assertArrayNotHasKey('source', $comResult);
    }

    public function test_services_endpoint_lists_active_catalog(): void
    {
        $reseller = $this->createReseller();

        ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'product_id' => null,
            'name' => 'Starter Plan',
            'description' => 'Basic hosting',
            'type' => 'shared_hosting',
            'direct_admin_package_name' => 'starter',
            'monthly_price' => 500,
            'yearly_price' => 5000,
            'setup_fee' => 0,
            'is_active' => true,
        ]);

        $response = $this->onResellerHost('/api/v1/public/services');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'services')
            ->assertJsonPath('services.0.name', 'Starter Plan')
            ->assertJsonPath('services.0.monthly_price', 500);
    }

    public function test_cart_prepares_session_and_returns_checkout_url(): void
    {
        $reseller = $this->createReseller();
        $com = $this->seedExtension('.com', 1000, 1500);
        $this->enableResellerRetail($reseller, $com, 1999);

        $this->mock(DomainAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('checkInput')->andReturn([
                'available' => true,
                'full_domain' => 'acme.com',
                'name' => 'acme',
                'extension' => '.com',
                'source' => 'test',
            ]);
        });

        $response = $this->postOnResellerHost('/api/v1/public/cart', [
            'items' => [
                ['type' => 'domain', 'full_domain' => 'acme.com', 'years' => 1],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('item_count', 1)
            ->assertJsonPath('checkout_url', 'https://'.self::HOST.'/checkout');

        $this->assertCount(1, session(CheckoutController::CART_SESSION_KEY, []));
        $this->assertSame($reseller->id, session('registration_reseller_id'));
    }

    public function test_cors_allows_configured_origin(): void
    {
        $this->createReseller();

        $response = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->call('OPTIONS', 'https://'.self::HOST.'/api/v1/public/services', [], [], [], [
                'HTTP_ORIGIN' => 'https://www.acme.test',
            ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://www.acme.test');
    }

    public function test_guest_checkout_on_reseller_host_assigns_reseller_id(): void
    {
        $reseller = $this->createReseller();
        $com = $this->seedExtension('.com', 1000, 1500);
        $this->enableResellerRetail($reseller, $com, 1999);

        session([
            CheckoutController::CART_SESSION_KEY => [
                [
                    'type' => 'domain',
                    'domain' => 'buy',
                    'extension' => '.com',
                    'full_domain' => 'buy.com',
                    'years' => 1,
                    'reseller_id' => $reseller->id,
                ],
            ],
            'registration_reseller_id' => $reseller->id,
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/checkout');

        $response->assertOk();
        $response->assertSee('buy.com', false);
    }
}
