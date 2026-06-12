<?php

namespace Tests\Feature\Reseller;

use App\Enums\ResellerDomainOrderType;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Invoice;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Support\ResellerCartContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerDomainTransferTest extends TestCase
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

    private function seedExtensionWithTransfer(string $extension, float $transferPrice): DomainExtension
    {
        $ext = DomainExtension::create([
            'extension' => $extension,
            'enabled' => true,
            'transfer_price' => $transferPrice,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $ext->id,
            'period_years' => 1,
            'tier' => 'wholesale',
            'price' => 1000,
            'enabled' => true,
        ]);

        return $ext;
    }

    public function test_transfer_pricing_api_returns_wholesale_rate(): void
    {
        $reseller = $this->createReseller();
        $extension = $this->seedExtensionWithTransfer('.xfer', 850);

        ResellerCartContext::setSelf();

        $this->actingAs($reseller)
            ->getJson(route('reseller.domains.pricing.api', ['extension' => $extension->extension]).'?type=transfer')
            ->assertOk()
            ->assertJson([
                'available' => true,
                'type' => 'transfer',
                'line_total' => 850.0,
                'wholesale_line_total' => 850.0,
                'retail' => false,
            ]);
    }

    public function test_reseller_can_add_transfer_to_cart_and_checkout_at_wholesale(): void
    {
        $reseller = $this->createReseller();
        $this->seedExtensionWithTransfer('.xfer', 850);

        ResellerCartContext::setSelf();

        $this->actingAs($reseller)
            ->postJson(route('reseller.cart.transfer'), [
                'domain' => 'mydomain',
                'extension' => '.xfer',
                'price' => 850,
                'epp_code' => 'EPP-CODE-12345',
                'old_registrar' => 'GoDaddy',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->actingAs($reseller)
            ->post(route('reseller.checkout.process'), ['agree' => '1'])
            ->assertRedirect();

        $order = ResellerDomainOrder::query()->first();
        $this->assertNotNull($order);
        $this->assertTrue($order->isTransfer());
        $this->assertSame(ResellerDomainOrderType::Transfer, $order->order_type);
        $this->assertSame(850.0, (float) $order->wholesale_amount);
        $this->assertSame($reseller->id, $order->customer_id);

        $domain = Domain::query()->where('name', 'mydomain')->first();
        $this->assertNotNull($domain);
        $this->assertSame('transfer', $domain->type);
        $this->assertSame('EPP-CODE-12345', $domain->epp_code);

        $invoice = Invoice::query()->where('user_id', $reseller->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(850.0, (float) $invoice->total);
    }

    public function test_customer_mode_transfer_uses_retail_transfer_price(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $extension = $this->seedExtensionWithTransfer('.xfer', 800);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => 1200,
            'enabled' => true,
        ]);

        ResellerCartContext::setCustomer($customer->id);

        $pricing = $this->actingAs($reseller)
            ->getJson(route('reseller.domains.pricing.api', ['extension' => $extension->extension]).'?type=transfer&retail=1');

        $pricing->assertOk();
        $retailPrice = (float) $pricing->json('line_total');
        $this->assertGreaterThan(800, $retailPrice);

        $this->actingAs($reseller)
            ->postJson(route('reseller.cart.transfer'), [
                'domain' => 'clientdomain',
                'extension' => '.xfer',
                'price' => $retailPrice,
                'epp_code' => 'EPP-CLIENT-99999',
                'old_registrar' => 'Namecheap',
            ])
            ->assertOk();

        $this->actingAs($reseller)
            ->post(route('reseller.checkout.process'), ['agree' => '1'])
            ->assertRedirect();

        $order = ResellerDomainOrder::query()->first();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame(800.0, (float) $order->wholesale_amount);
        $this->assertGreaterThan(0, (float) $order->retail_amount);
    }
}
