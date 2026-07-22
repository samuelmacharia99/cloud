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
}
