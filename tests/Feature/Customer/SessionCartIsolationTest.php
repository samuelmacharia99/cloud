<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\CheckoutController;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Product;
use App\Models\User;
use App\Support\SessionCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionCartIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedRetailDotCom(): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => '.com',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => 1500,
            'enabled' => true,
        ]);

        return $ext;
    }

    public function test_sync_cart_merges_domains_without_wiping_portal_products(): void
    {
        $user = User::factory()->customer()->create();
        $product = Product::factory()->create([
            'type' => 'container_hosting',
            'is_active' => true,
            'monthly_price' => 1000,
        ]);

        $this->actingAs($user);
        SessionCart::putPortal([
            'host1' => [
                'type' => 'product',
                'product_id' => $product->id,
                'billing_cycle' => 'monthly',
            ],
        ]);

        $this->postJson(route('checkout.sync-cart'), [
            'cart' => [
                [
                    'type' => 'domain',
                    'full_domain' => 'example.com',
                    'years' => 1,
                    'price' => 1500,
                ],
            ],
        ])->assertOk()->assertJson(['success' => true]);

        $cart = SessionCart::portal();
        $this->assertCount(2, $cart);
        $types = collect($cart)->pluck('type')->sort()->values()->all();
        $this->assertSame(['domain', 'product'], $types);
    }

    public function test_storefront_clear_does_not_wipe_portal_cart(): void
    {
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        SessionCart::putPortal([
            'p1' => ['type' => 'product', 'product_id' => 1, 'billing_cycle' => 'monthly'],
        ]);
        SessionCart::putStorefront([
            's1' => ['type' => 'domain', 'domain' => 'x', 'extension' => '.com', 'years' => 1],
        ]);

        SessionCart::clearStorefront();

        $this->assertCount(1, SessionCart::portal());
        $this->assertSame([], SessionCart::storefront());
    }

    public function test_portal_cart_is_scoped_per_user(): void
    {
        $a = User::factory()->customer()->create();
        $b = User::factory()->customer()->create();

        $this->actingAs($a);
        SessionCart::putPortal([
            'a1' => ['type' => 'product', 'product_id' => 9, 'billing_cycle' => 'monthly'],
        ]);

        $this->actingAs($b);
        $this->assertSame([], SessionCart::portal());

        SessionCart::putPortal([
            'b1' => ['type' => 'product', 'product_id' => 8, 'billing_cycle' => 'monthly'],
        ]);

        $this->actingAs($a);
        $this->assertArrayHasKey('a1', SessionCart::portal());
        $this->assertArrayNotHasKey('b1', SessionCart::portal());
    }

    public function test_legacy_cart_key_migrates_into_user_scoped_portal(): void
    {
        $user = User::factory()->customer()->create();
        $this->seedRetailDotCom();

        $legacy = [
            'legacy1' => [
                'type' => 'domain',
                'domain' => 'legacy',
                'extension' => '.com',
                'years' => 1,
            ],
        ];

        $this->actingAs($user)
            ->withSession([CheckoutController::CART_SESSION_KEY => $legacy])
            ->get(route('customer.cart.index'))
            ->assertOk()
            ->assertSee('legacy.com');

        $this->assertArrayHasKey('legacy1', SessionCart::portal());
        $this->assertFalse(session()->has(CheckoutController::CART_SESSION_KEY));
    }
}
