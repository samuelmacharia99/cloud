<?php

namespace Tests\Feature\Reseller;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResellerDomainDnsTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter-'.uniqid(),
            'description' => 'Test',
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

    private function enableCloudflare(): void
    {
        Setting::setValue('cloudflare_enabled', 'true');
        Setting::setValue('cloudflare_api_token', 'test-token-abcdefghijklmnopqrstuvwxyz');
        Setting::setValue('cloudflare_account_id', 'acct123');
        Setting::setValue('cloudflare_branded_ns1', 'albert.ns.cloudflare.com');
        Setting::setValue('cloudflare_branded_ns2', 'aliza.ns.cloudflare.com');
    }

    private function createManagedDomain(User $reseller, ?User $owner = null, array $overrides = []): Domain
    {
        $owner ??= $reseller;

        return Domain::create(array_merge([
            'user_id' => $owner->id,
            'reseller_id' => $reseller->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ], $overrides));
    }

    private function fakeCloudflareZone(string $zoneId = 'zone-reseller'): void
    {
        Http::fake([
            "api.cloudflare.com/client/v4/zones/{$zoneId}" => Http::response([
                'success' => true,
                'result' => [
                    'id' => $zoneId,
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ], 200),
            "api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records*" => Http::response([
                'success' => true,
                'result' => [
                    [
                        'id' => 'rec-www',
                        'type' => 'A',
                        'name' => 'www.shop.com',
                        'content' => '1.2.3.4',
                        'ttl' => 3600,
                        'proxied' => false,
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_reseller_can_open_dns_page_for_own_domain(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $domain = $this->createManagedDomain($reseller, $reseller, [
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-reseller',
        ]);
        $this->fakeCloudflareZone();

        $this->actingAs($reseller)
            ->get(route('reseller.domains.dns.index', $domain))
            ->assertOk()
            ->assertSee('DNS Management')
            ->assertSee('Add DNS Record')
            ->assertSee('www')
            ->assertSee('1.2.3.4');
    }

    public function test_reseller_can_open_dns_page_for_customer_domain(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $domain = $this->createManagedDomain($reseller, $customer, [
            'name' => 'client',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-reseller',
        ]);
        $this->fakeCloudflareZone();

        $this->actingAs($reseller)
            ->get(route('reseller.domains.dns.index', $domain))
            ->assertOk()
            ->assertSee('client.com');
    }

    public function test_foreign_reseller_cannot_manage_dns(): void
    {
        $owner = $this->createReseller();
        $other = $this->createReseller();
        $domain = $this->createManagedDomain($owner);

        $this->actingAs($other)
            ->get(route('reseller.domains.dns.index', $domain))
            ->assertForbidden();

        $this->actingAs($other)
            ->post(route('reseller.domains.dns.add-record', $domain), [
                'name' => 'www',
                'type' => 'A',
                'content' => '1.2.3.4',
            ])
            ->assertForbidden();
    }

    public function test_customer_cannot_use_reseller_dns_routes(): void
    {
        $reseller = $this->createReseller();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);
        $domain = $this->createManagedDomain($reseller, $customer);

        $this->actingAs($customer)
            ->get(route('reseller.domains.dns.index', $domain))
            ->assertForbidden();
    }

    public function test_reseller_can_enable_cloudflare_dns(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $domain = $this->createManagedDomain($reseller);

        Http::fake([
            'api.cloudflare.com/client/v4/zones' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name_servers' => ['albert.ns.cloudflare.com', 'aliza.ns.cloudflare.com'],
                ],
            ], 200),
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.dns.provision', $domain))
            ->assertRedirect(route('reseller.domains.dns.index', $domain))
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertTrue($domain->cloudflare_dns_enabled);
        $this->assertSame('zone-new', $domain->cloudflare_zone_id);
        $this->assertSame('albert.ns.cloudflare.com', $domain->nameserver_1);
        $this->assertSame('aliza.ns.cloudflare.com', $domain->nameserver_2);
    }

    public function test_enabling_dns_pushes_cloudflare_nameservers_when_domain_is_at_cosmotown(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $this->attachCosmotown('.com');
        $domain = $this->createManagedDomain($reseller, $reseller, [
            'registrar_handle' => 'shop.com',
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ], 200),
            'api.cloudflare.com/client/v4/zones/zone-new' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'shop.com',
                'nameservers' => ['ns1.old.example', 'ns2.old.example'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.dns.provision', $domain))
            ->assertRedirect(route('reseller.domains.dns.index', $domain))
            ->assertSessionHas('success');

        $message = session('success');
        $this->assertIsString($message);
        $this->assertStringContainsString('ezra.ns.cloudflare.com', $message);
        $this->assertStringContainsString('registry', strtolower($message));
        $this->assertStringNotContainsStringIgnoringCase('cosmotown', $message);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'reseller/savedomainnameservers')
            && ($request->data()['domain'] ?? null) === 'shop.com'
            && ($request->data()['nameservers'] ?? []) === ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com']);

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ezra.ns.cloudflare.com',
            'nameserver_2' => 'liberty.ns.cloudflare.com',
            'cloudflare_dns_enabled' => 1,
        ]);
    }

    public function test_enabling_dns_does_not_push_nameservers_when_domain_is_not_at_cosmotown(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $this->attachCosmotown('.com');
        $domain = $this->createManagedDomain($reseller);

        Http::fake([
            'api.cloudflare.com/client/v4/zones' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ], 200),
            'api.cloudflare.com/client/v4/zones/zone-new' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'message' => 'Domain not found',
            ], 404),
            'sandbox.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.dns.provision', $domain))
            ->assertRedirect(route('reseller.domains.dns.index', $domain))
            ->assertSessionHas('success');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'reseller/savedomainnameservers'));

        $domain->refresh();
        $this->assertSame('ezra.ns.cloudflare.com', $domain->nameserver_1);
        $this->assertSame('liberty.ns.cloudflare.com', $domain->nameserver_2);
    }

    public function test_reseller_can_add_and_delete_dns_records(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $domain = $this->createManagedDomain($reseller, $reseller, [
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-reseller',
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-reseller/dns_records' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'rec-new',
                    'type' => 'A',
                    'name' => 'www.shop.com',
                    'content' => '9.9.9.9',
                    'ttl' => 3600,
                    'proxied' => false,
                ],
            ], 200),
            'api.cloudflare.com/client/v4/zones/zone-reseller/dns_records/rec-new' => Http::response([
                'success' => true,
                'result' => ['id' => 'rec-new'],
            ], 200),
        ]);

        $this->actingAs($reseller)
            ->post(route('reseller.domains.dns.add-record', $domain), [
                'name' => 'www',
                'type' => 'A',
                'content' => '9.9.9.9',
                'ttl' => 3600,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($reseller)
            ->delete(route('reseller.domains.dns.delete-record', [$domain, 'rec-new']))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_reseller_dns_record_requires_name_and_content(): void
    {
        $this->enableCloudflare();
        $reseller = $this->createReseller();
        $domain = $this->createManagedDomain($reseller, $reseller, [
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-reseller',
        ]);

        $this->actingAs($reseller)
            ->from(route('reseller.domains.dns.index', $domain))
            ->post(route('reseller.domains.dns.add-record', $domain), [
                'type' => 'A',
            ])
            ->assertRedirect(route('reseller.domains.dns.index', $domain))
            ->assertSessionHasErrors(['name', 'content']);
    }

    public function test_reseller_domain_pages_link_to_dns_manager(): void
    {
        $reseller = $this->createReseller();
        $domain = $this->createManagedDomain($reseller);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.index'))
            ->assertOk()
            ->assertSee(route('reseller.domains.dns.index', $domain), false);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.show', $domain))
            ->assertOk()
            ->assertSee('Manage DNS')
            ->assertSee(route('reseller.domains.dns.index', $domain), false);
    }

    private function attachCosmotown(string $extension): Registrar
    {
        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-reseller-dns-'.uniqid(),
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => true,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);

        DomainExtension::query()->firstOrCreate(
            ['extension' => $extension],
            ['description' => 'COM', 'enabled' => true]
        )->update(['registrar_id' => $registrar->id, 'enabled' => true]);

        return $registrar;
    }
}
