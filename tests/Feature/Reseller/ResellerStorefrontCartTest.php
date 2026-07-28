<?php

namespace Tests\Feature\Reseller;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\ResellerProduct;
use App\Models\User;
use App\Services\ResellerBrandingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResellerStorefrontCartTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'billing.acme.test';

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
            'settings' => [
                'branding' => [
                    'company_name' => 'Acme Hosting',
                    'tagline' => 'Reliable hosting',
                    'custom_domain' => self::HOST,
                    'primary_color' => '#1a4b8c',
                    'landing_enabled' => true,
                    'landing_template' => 'legacy',
                    'landing_show_domains' => true,
                    'landing_show_hosting' => true,
                ],
            ],
        ]);
    }

    private function seedProduct(User $reseller): ResellerProduct
    {
        return ResellerProduct::create([
            'reseller_id' => $reseller->id,
            'name' => 'Starter Web',
            'description' => 'Shared hosting starter',
            'type' => 'shared_hosting',
            'direct_admin_package_name' => 'starter',
            'monthly_price' => 999,
            'yearly_price' => 9990,
            'setup_fee' => 0,
            'is_active' => true,
            'features' => ['10 GB SSD'],
        ]);
    }

    private function seedDomain(User $reseller): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
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
            'price' => 1500,
            'enabled' => true,
        ]);
        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'retail_price' => 1500,
            'enabled' => true,
        ]);

        return $ext;
    }

    public function test_add_to_cart_appends_items_and_opens_cart_page(): void
    {
        $reseller = $this->createReseller();
        $product = $this->seedProduct($reseller);
        app(ResellerBrandingResolver::class)->forgetDomainCache(self::HOST);

        $response = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->postJson('https://'.self::HOST.'/store/cart', [
                'items' => [[
                    'type' => 'reseller_product',
                    'id' => $product->id,
                    'reseller_product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                ]],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('item_count', 1)
            ->assertJsonStructure(['cart_url']);

        $this->assertCount(1, session(CheckoutController::CART_SESSION_KEY));

        $response2 = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->postJson('https://'.self::HOST.'/store/cart', [
                'items' => [[
                    'type' => 'reseller_product',
                    'id' => $product->id,
                    'reseller_product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                ]],
            ]);

        $response2->assertOk()->assertJsonPath('item_count', 2);
        $this->assertCount(2, session(CheckoutController::CART_SESSION_KEY));

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/cart')
            ->assertOk()
            ->assertSee('Shopping Cart')
            ->assertSee('Starter Web')
            ->assertSee('Checkout');
    }

    public function test_checkout_shows_login_and_register_options(): void
    {
        $reseller = $this->createReseller();
        $product = $this->seedProduct($reseller);
        app(ResellerBrandingResolver::class)->forgetDomainCache(self::HOST);

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->postJson('https://'.self::HOST.'/store/cart', [
                'items' => [[
                    'type' => 'reseller_product',
                    'id' => $product->id,
                    'reseller_product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                ]],
            ]);

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/checkout')
            ->assertOk()
            ->assertSee('Existing Customer')
            ->assertSee('New Customer')
            ->assertSee('Create Your Account')
            ->assertSee('Log in to pay')
            ->assertSee('999');
    }

    public function test_checkout_keeps_reseller_retail_price_not_zero(): void
    {
        $reseller = $this->createReseller();
        $product = $this->seedProduct($reseller);
        app(ResellerBrandingResolver::class)->forgetDomainCache(self::HOST);

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->postJson('https://'.self::HOST.'/store/cart', [
                'items' => [[
                    'type' => 'reseller_product',
                    'id' => $product->id,
                    'reseller_product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                ]],
            ])
            ->assertOk();

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/cart')
            ->assertOk()
            ->assertSee('999');

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/checkout')
            ->assertOk()
            ->assertSee('Starter Web')
            ->assertSee('999');
    }

    public function test_existing_customer_can_login_at_checkout(): void
    {
        $reseller = $this->createReseller();
        $product = $this->seedProduct($reseller);
        app(ResellerBrandingResolver::class)->forgetDomainCache(self::HOST);

        $customer = User::factory()->create([
            'reseller_id' => $reseller->id,
            'email' => 'client@acme.test',
            'password' => Hash::make('password123'),
            'email_verified_at' => now(),
        ]);

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->postJson('https://'.self::HOST.'/store/cart', [
                'items' => [[
                    'type' => 'reseller_product',
                    'id' => $product->id,
                    'reseller_product_id' => $product->id,
                    'billing_cycle' => 'monthly',
                ]],
            ]);

        $response = $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->post('https://'.self::HOST.'/store/checkout/login', [
                'email' => 'client@acme.test',
                'password' => 'password123',
                'checkout_mode' => 'login',
            ]);

        $response->assertRedirect(route('customer.checkout.show'));
        $this->assertAuthenticatedAs($customer);

        $this->withServerVariables(['HTTP_HOST' => self::HOST])
            ->get('https://'.self::HOST.'/checkout')
            ->assertOk()
            ->assertSee('Complete Your Order')
            ->assertSee('client@acme.test');
    }
}
