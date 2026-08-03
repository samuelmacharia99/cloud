<?php

namespace Tests\Feature\Customer;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerAddDnsDomainTest extends TestCase
{
    use RefreshDatabase;

    private function enableCloudflare(): void
    {
        Setting::setValue('cloudflare_enabled', 'true');
        Setting::setValue('cloudflare_api_token', 'test-token-abcdefghijklmnopqrstuvwxyz');
        Setting::setValue('cloudflare_account_id', 'acct123');
        Setting::setValue('cloudflare_branded_ns1', 'albert.ns.cloudflare.com');
        Setting::setValue('cloudflare_branded_ns2', 'aliza.ns.cloudflare.com');
    }

    public function test_customer_can_add_external_domain_for_dns_management(): void
    {
        $this->enableCloudflare();

        DomainExtension::create([
            'extension' => '.co.ke',
            'description' => 'Kenya',
            'enabled' => true,
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-abc',
                    'name_servers' => ['albert.ns.cloudflare.com', 'aliza.ns.cloudflare.com'],
                ],
            ], 200),
        ]);

        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->post(route('customer.domains.dns.store'), [
            'domain' => 'mybiz.co.ke',
        ]);

        $domain = Domain::query()->where('name', 'mybiz')->where('extension', '.co.ke')->first();
        $this->assertNotNull($domain);
        $this->assertSame('dns', $domain->type);
        $this->assertTrue($domain->cloudflare_dns_enabled);
        $this->assertSame('zone-abc', $domain->cloudflare_zone_id);
        $this->assertSame($customer->id, $domain->user_id);

        $response->assertRedirect(route('customer.domains.dns.index', $domain));
    }

    public function test_duplicate_domain_is_rejected(): void
    {
        $this->enableCloudflare();

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        $customer = User::factory()->customer()->create();

        Domain::create([
            'user_id' => $customer->id,
            'name' => 'taken',
            'extension' => '.com',
            'type' => 'registration',
            'status' => 'active',
        ]);

        $this->actingAs($customer)
            ->post(route('customer.domains.dns.store'), ['domain' => 'taken.com'])
            ->assertSessionHasErrors('domain');

        $this->assertSame(1, Domain::query()->where('name', 'taken')->count());
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        $this->enableCloudflare();

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->post(route('customer.domains.dns.store'), ['domain' => 'weird.invalidtld'])
            ->assertSessionHasErrors('domain');
    }

    public function test_reseller_customer_can_add_cloudflare_dns_domain(): void
    {
        $this->enableCloudflare();

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-reseller-abc',
                    'name_servers' => ['albert.ns.cloudflare.com', 'aliza.ns.cloudflare.com'],
                ],
            ], 200),
        ]);

        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $response = $this->actingAs($customer)->post(route('customer.domains.dns.store'), [
            'domain' => 'mybiz.com',
        ]);

        $domain = Domain::query()->where('name', 'mybiz')->where('extension', '.com')->first();
        $this->assertNotNull($domain);
        $this->assertSame('dns', $domain->type);
        $this->assertTrue($domain->cloudflare_dns_enabled);
        $this->assertSame('zone-reseller-abc', $domain->cloudflare_zone_id);
        $this->assertSame($customer->id, $domain->user_id);

        $response->assertRedirect(route('customer.domains.dns.index', $domain));
    }

    public function test_reseller_customer_sees_cloudflare_on_domains_index(): void
    {
        $this->enableCloudflare();

        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($customer)
            ->get(route('customer.domains.index'))
            ->assertOk()
            ->assertViewHas('cloudflareDnsAvailable', true);
    }

    public function test_reseller_customer_can_toggle_cloudflare_dns_in_cart(): void
    {
        $this->enableCloudflare();

        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        session(['cart' => [
            'domain_example_com' => [
                'key' => 'domain_example_com',
                'type' => 'domain',
                'domain' => 'example',
                'extension' => '.com',
                'cloudflare_dns' => false,
            ],
        ]]);

        $this->actingAs($customer)
            ->postJson(route('customer.cart.cloudflare-dns', ['key' => 'domain_example_com']), [
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $item = session('cart.domain_example_com');
        $this->assertTrue($item['cloudflare_dns']);
        $this->assertSame('albert.ns.cloudflare.com', $item['nameservers']['ns1']);
    }

    public function test_saving_platform_nameservers_clears_cloudflare_dns_in_cart(): void
    {
        $this->enableCloudflare();
        Setting::setValue('domain_ns1', 'riv1.talksasa.com');
        Setting::setValue('domain_ns2', 'riv2.talksasa.com');

        $customer = User::factory()->customer()->create();

        session(['cart' => [
            'domain_example_com' => [
                'type' => 'domain',
                'domain' => 'example',
                'extension' => '.com',
                'years' => 1,
                'cloudflare_dns' => true,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'albert.ns.cloudflare.com',
                    'ns2' => 'aliza.ns.cloudflare.com',
                ],
            ],
        ]]);

        $this->actingAs($customer)
            ->postJson(route('customer.cart.nameservers', ['key' => 'domain_example_com']), [
                'use_default' => true,
                'ns1' => 'riv1.talksasa.com',
                'ns2' => 'riv2.talksasa.com',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $item = session('cart.domain_example_com');
        $this->assertFalse($item['cloudflare_dns']);
        $this->assertTrue($item['nameservers']['use_default']);
        $this->assertSame('riv1.talksasa.com', $item['nameservers']['ns1']);
    }

    public function test_disabling_cloudflare_dns_restores_platform_nameservers_in_cart(): void
    {
        $this->enableCloudflare();
        Setting::setValue('domain_ns1', 'riv1.talksasa.com');
        Setting::setValue('domain_ns2', 'riv2.talksasa.com');

        $customer = User::factory()->customer()->create();

        session(['cart' => [
            'domain_example_com' => [
                'type' => 'domain',
                'domain' => 'example',
                'extension' => '.com',
                'years' => 1,
                'cloudflare_dns' => true,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'albert.ns.cloudflare.com',
                    'ns2' => 'aliza.ns.cloudflare.com',
                ],
            ],
        ]]);

        $this->actingAs($customer)
            ->postJson(route('customer.cart.cloudflare-dns', ['key' => 'domain_example_com']), [
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $item = session('cart.domain_example_com');
        $this->assertFalse($item['cloudflare_dns']);
        $this->assertSame('riv1.talksasa.com', $item['nameservers']['ns1']);
        $this->assertSame('riv2.talksasa.com', $item['nameservers']['ns2']);
    }

    public function test_cart_page_uses_my_cart_dns_endpoints(): void
    {
        $this->enableCloudflare();

        $extension = DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            [
                'description' => 'COM',
                'enabled' => true,
            ]
        );
        $extension->update(['enabled' => true]);

        \App\Models\DomainPricing::query()->updateOrCreate(
            [
                'domain_extension_id' => $extension->id,
                'period_years' => 1,
                'tier' => 'retail',
            ],
            [
                'price' => 1650,
                'renewal_price' => 1650,
                'setup_fee' => 0,
                'enabled' => true,
            ]
        );

        $customer = User::factory()->customer()->create();

        session(['cart' => [
            'domain_example_com' => [
                'type' => 'domain',
                'domain' => 'example',
                'extension' => '.com',
                'years' => 1,
                'cloudflare_dns' => true,
                'nameservers' => [
                    'use_default' => true,
                    'ns1' => 'albert.ns.cloudflare.com',
                    'ns2' => 'aliza.ns.cloudflare.com',
                ],
            ],
        ]]);

        $this->actingAs($customer)
            ->get(route('customer.cart.index'))
            ->assertOk()
            ->assertSee('DNS / Name Servers', false)
            ->assertSee('/my/cart/', false)
            ->assertDontSee("fetch(`/cart/", false)
            ->assertDontSee('Include managed DNS (Cloudflare)', false);
    }
}
