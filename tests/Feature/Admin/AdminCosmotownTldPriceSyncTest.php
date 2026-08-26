<?php

namespace Tests\Feature\Admin;

use App\Enums\RegistrarDriver;
use App\Models\Currency;
use App\Models\DomainExtension;
use App\Models\DomainPricing;
use App\Models\Registrar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminCosmotownTldPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_cosmotown_tld_prices_on_pricing_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 0.01, 'is_active' => true]
        );

        Registrar::query()->updateOrCreate(
            ['slug' => 'cosmotown'],
            [
                'name' => 'Cosmotown',
                'driver' => RegistrarDriver::Cosmotown,
                'environment' => 'production',
                'is_active' => true,
                'is_default' => true,
                'config' => ['api_token' => 'test-token'],
                'sort_order' => 0,
            ]
        );

        $extension = DomainExtension::create([
            'extension' => '.com',
            'description' => 'Commercial',
            'registrar' => 'Cosmotown',
            'enabled' => true,
        ]);

        DomainPricing::create([
            'domain_extension_id' => $extension->id,
            'period_years' => 1,
            'tier' => 'retail',
            'price' => 1500,
            'renewal_price' => 1500,
            'enabled' => true,
        ]);

        Http::fake([
            'www.cosmotown.com/v1/reseller/tldprice*' => Http::response([
                'com' => [
                    'register' => ['1' => '7.59'],
                    'renew' => ['1' => '11.08'],
                    'transfer' => ['1' => '11.08'],
                    'currency' => 'USD',
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.domains.pricing'))
            ->assertOk()
            ->assertSee('Sync Cosmotown costs');

        $this->actingAs($admin)
            ->post(route('admin.domains.sync-cosmotown-tld-prices'))
            ->assertRedirect(route('admin.domains.pricing'))
            ->assertSessionHas('success');

        $extension->refresh();

        $this->assertSame('7.59', (string) $extension->registrar_register_cost_usd);
        $this->assertSame('11.08', (string) $extension->registrar_renew_cost_usd);
        $this->assertSame('759.00', number_format((float) $extension->registrar_register_cost_kes, 2, '.', ''));
        $this->assertNotNull($extension->registrar_cost_synced_at);
    }

    public function test_non_admin_cannot_sync_cosmotown_tld_prices(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);

        $this->actingAs($customer)
            ->post(route('admin.domains.sync-cosmotown-tld-prices'))
            ->assertForbidden();
    }
}
