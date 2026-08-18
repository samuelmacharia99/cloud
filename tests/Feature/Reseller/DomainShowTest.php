<?php

namespace Tests\Feature\Reseller;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Models\ResellerPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainShowTest extends TestCase
{
    use RefreshDatabase;

    private function createReseller(): User
    {
        $package = ResellerPackage::create([
            'name' => 'Starter',
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

    public function test_reseller_can_view_domain_detail_page(): void
    {
        $reseller = $this->createReseller();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'nameserver_1' => 'ns1.example.com',
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.show', $domain))
            ->assertOk()
            ->assertSee('example.com')
            ->assertSee('ns1.example.com')
            ->assertSee('EPP / auth code')
            ->assertDontSee('Cosmotown')
            ->assertDontSee('cosmotown');
    }

    public function test_reseller_sees_live_registry_nameservers_and_epp_without_provider_name(): void
    {
        $reseller = $this->createReseller();
        $this->attachCosmotown('.com');

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'registrar_handle' => 'shop.com',
            'nameserver_1' => 'ns1.stale.com',
            'nameserver_2' => 'ns2.stale.com',
        ]);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'shop.com',
                'nameservers' => ['ns1.live.example', 'ns2.live.example'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'auth_code' => 'EPP-LIVE-401',
            ], 200),
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.show', $domain))
            ->assertOk()
            ->assertSee('ns1.live.example')
            ->assertSee('ns2.live.example')
            ->assertSee('EPP-LIVE-401')
            ->assertSee('Live from registry')
            ->assertDontSee('ns1.stale.com')
            ->assertDontSee('Cosmotown')
            ->assertDontSee('cosmotown');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.live.example',
            'nameserver_2' => 'ns2.live.example',
            'epp_code' => 'EPP-LIVE-401',
        ]);
    }

    public function test_reseller_loads_epp_from_cosmotown_even_when_tld_is_mapped_elsewhere(): void
    {
        $reseller = $this->createReseller();

        Registrar::query()->create([
            'name' => 'Openprovider',
            'slug' => 'openprovider-default',
            'driver' => RegistrarDriver::Openprovider,
            'environment' => 'production',
            'is_active' => true,
            'is_default' => true,
            'config' => ['username' => 'op', 'password' => 'secret'],
            'sort_order' => 0,
        ]);

        $this->attachCosmotown('.com', default: false, bindExtension: false);

        $extension = DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );
        $extension->update(['enabled' => true, 'registrar_id' => Registrar::query()->where('slug', 'openprovider-default')->value('id')]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'chiefdaking.services',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'nameserver_1' => 'albert.ns.cloudflare.com',
            'nameserver_2' => 'aliza.ns.cloudflare.com',
        ]);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'chiefdaking.services.com',
                'nameservers' => ['albert.ns.cloudflare.com', 'aliza.ns.cloudflare.com'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'data' => ['auth_code' => 'EPP-CHIEF-DAKING'],
            ], 200),
        ]);

        $this->actingAs($reseller)
            ->get(route('reseller.domains.show', $domain))
            ->assertOk()
            ->assertSee('EPP-CHIEF-DAKING')
            ->assertDontSee('Cosmotown')
            ->assertDontSee('appears here after the domain is registered');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'epp_code' => 'EPP-CHIEF-DAKING',
        ]);
    }

    public function test_reseller_can_update_nameservers(): void
    {
        $reseller = $this->createReseller();

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $this->actingAs($reseller)
            ->put(route('reseller.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.new.com',
                'nameserver_2' => 'ns2.new.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning')
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.new.com',
            'nameserver_2' => 'ns2.new.com',
        ]);
    }

    public function test_reseller_nameserver_save_does_not_name_the_upstream_provider(): void
    {
        $reseller = $this->createReseller();
        $this->attachCosmotown('.com');

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'reseller_id' => $reseller->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'registrar_external_id' => 'shop.com',
        ]);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
        ]);

        $this->actingAs($reseller)
            ->put(route('reseller.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.talksasa.com',
                'nameserver_2' => 'ns2.talksasa.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $message = session('success');
        $this->assertIsString($message);
        $this->assertStringNotContainsStringIgnoringCase('cosmotown', $message);
        $this->assertStringContainsString('registry', strtolower($message));
    }

    private function attachCosmotown(string $extension, bool $default = true, bool $bindExtension = true): Registrar
    {
        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-reseller-show-'.uniqid(),
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => $default,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);

        if ($bindExtension) {
            DomainExtension::query()->firstOrCreate(
                ['extension' => $extension],
                ['description' => 'COM', 'enabled' => true]
            )->update(['registrar_id' => $registrar->id, 'enabled' => true]);
        }

        return $registrar;
    }
}
