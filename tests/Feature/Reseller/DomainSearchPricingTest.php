<?php

namespace Tests\Feature\Reseller;

use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Support\ResellerCartContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainSearchPricingTest extends TestCase
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

    private function seedExtensionPricing(string $extension, float $wholesale, float $retail): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => $extension,
            'description' => strtoupper(ltrim($extension, '.')),
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 2,
            'tier' => 'wholesale',
            'price' => $wholesale,
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 2,
            'tier' => 'retail',
            'price' => $retail,
            'enabled' => true,
        ]);

        return $ext;
    }

    public function test_pricing_api_returns_wholesale_rates_in_self_cart_mode(): void
    {
        $reseller = $this->createReseller();
        $extension = $this->seedExtensionPricing('.co.ke', 1599.00, 2499.00);

        ResellerCartContext::setSelf();

        $response = $this->actingAs($reseller)
            ->getJson(route('reseller.domains.pricing.api', ['extension' => $extension->extension]).'?period=2');

        $response->assertOk()
            ->assertJson([
                'available' => true,
                'retail' => false,
                'line_total' => 1599.0,
                'wholesale_line_total' => 1599.0,
                'price' => 799.5,
                'wholesale_price' => 799.5,
                'currency' => 'KES',
            ]);
    }

    public function test_pricing_api_returns_retail_rates_in_customer_cart_mode(): void
    {
        $reseller = $this->createReseller();
        $extension = $this->seedExtensionPricing('.com', 1199.00, 1999.00);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'retail_price' => 2299.00,
            'enabled' => true,
        ]);

        ResellerCartContext::setCustomer(User::factory()->create(['reseller_id' => $reseller->id])->id);

        $response = $this->actingAs($reseller)
            ->getJson(route('reseller.domains.pricing.api', ['extension' => $extension->extension]).'?period=2');

        $response->assertOk()
            ->assertJson([
                'available' => true,
                'retail' => true,
                'line_total' => 2299.0,
                'wholesale_line_total' => 1199.0,
            ]);
    }

    public function test_reseller_can_save_renewal_retail_price(): void
    {
        $reseller = $this->createReseller();
        $extension = $this->seedExtensionPricing('.ke', 1000.00, 1500.00);

        DomainPricing::where('domain_extension_id', $extension->id)
            ->where('tier', 'wholesale')
            ->where('period_years', 2)
            ->update(['renewal_price' => 900.00]);

        $response = $this->actingAs($reseller)->post(route('reseller.domains.pricing.update'), [
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'retail_price' => 2000.00,
            'renewal_retail_price' => 1800.00,
            'enabled' => '1',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('reseller_domain_pricing', [
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'retail_price' => 2000.00,
            'renewal_retail_price' => 1800.00,
            'enabled' => true,
        ]);
    }

    public function test_pricing_api_returns_retail_renewal_rate_in_customer_cart_mode(): void
    {
        $reseller = $this->createReseller();
        $extension = $this->seedExtensionPricing('.org', 1199.00, 1999.00);

        DomainPricing::where('domain_extension_id', $extension->id)
            ->where('tier', 'wholesale')
            ->where('period_years', 2)
            ->update(['renewal_price' => 999.00]);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'retail_price' => 2299.00,
            'renewal_retail_price' => 1899.00,
            'enabled' => true,
        ]);

        ResellerCartContext::setCustomer(User::factory()->create(['reseller_id' => $reseller->id])->id);

        $response = $this->actingAs($reseller)
            ->getJson(route('reseller.domains.pricing.api', ['extension' => $extension->extension]).'?period=2');

        $response->assertOk()
            ->assertJson([
                'renewal_price' => 1899.0,
            ]);
    }

    public function test_get_pricing_for_user_uses_renewal_retail_for_reseller_customers(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $extension = $this->seedExtensionPricing('.net', 800.00, 1200.00);

        ResellerDomainPricing::create([
            'reseller_id' => $reseller->id,
            'domain_extension_id' => $extension->id,
            'period_years' => 2,
            'retail_price' => 2500.00,
            'renewal_retail_price' => 2100.00,
            'enabled' => true,
        ]);

        $pricing = $extension->getPricingForUser($customer, 2);

        $this->assertSame(2500.0, $pricing->price);
        $this->assertSame(2100.0, $pricing->renewal_price);
    }
}
