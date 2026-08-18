<?php

namespace Tests\Feature\Admin;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDomainNameserversTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_nameservers_on_domain_show_page(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = $this->domain();

        $this->actingAs($admin)
            ->get(route('admin.domains.show', $domain))
            ->assertOk()
            ->assertSee('Save nameservers')
            ->assertSee('EPP / auth code')
            ->assertSee('nameserver_1', false);

        $this->actingAs($admin)
            ->put(route('admin.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.new.com',
                'nameserver_2' => 'ns2.new.com',
                'nameserver_3' => 'ns3.new.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.new.com',
            'nameserver_2' => 'ns2.new.com',
            'nameserver_3' => 'ns3.new.com',
        ]);
    }

    public function test_admin_nameserver_update_requires_two_unique_nameservers(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = $this->domain();

        $this->actingAs($admin)
            ->from(route('admin.domains.show', $domain))
            ->put(route('admin.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.same.com',
                'nameserver_2' => 'ns1.same.com',
            ])
            ->assertRedirect(route('admin.domains.show', $domain))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.old.com',
            'nameserver_2' => 'ns2.old.com',
        ]);
    }

    public function test_admin_nameserver_update_pushes_to_cosmotown_when_linked(): void
    {
        $admin = User::factory()->admin()->create();
        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-ns-test',
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'production',
            'is_active' => true,
            'is_default' => true,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);

        $extension = DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );
        $extension->update(['registrar_id' => $registrar->id]);

        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'registrar_external_id' => 'shop.com',
            'nameserver_1' => 'ns1.old.com',
            'nameserver_2' => 'ns2.old.com',
        ]);

        Http::fake([
            'www.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.talksasa.com',
                'nameserver_2' => 'ns2.talksasa.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.talksasa.com',
            'nameserver_2' => 'ns2.talksasa.com',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'reseller/savedomainnameservers')
                && ($body['domain'] ?? null) === 'shop.com'
                && ($body['nameservers'] ?? []) === ['ns1.talksasa.com', 'ns2.talksasa.com'];
        });
    }

    public function test_admin_sees_live_epp_from_cosmotown_on_domain_show(): void
    {
        $admin = User::factory()->admin()->create();
        $this->attachCosmotown('.com');

        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
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
                'auth_code' => 'EPP-ADMIN-401',
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.domains.show', $domain))
            ->assertOk()
            ->assertSee('EPP-ADMIN-401')
            ->assertSee('Live from Cosmotown')
            ->assertSee('ns1.live.example')
            ->assertSee('ns2.live.example')
            ->assertDontSee('ns1.stale.com');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'nameserver_1' => 'ns1.live.example',
            'nameserver_2' => 'ns2.live.example',
            'epp_code' => 'EPP-ADMIN-401',
        ]);
    }

    public function test_admin_loads_epp_from_cosmotown_even_when_tld_is_mapped_elsewhere(): void
    {
        $admin = User::factory()->admin()->create();

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
        $extension->update([
            'enabled' => true,
            'registrar_id' => Registrar::query()->where('slug', 'openprovider-default')->value('id'),
        ]);

        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
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

        $this->actingAs($admin)
            ->get(route('admin.domains.show', $domain))
            ->assertOk()
            ->assertSee('EPP-CHIEF-DAKING')
            ->assertSee('Live from Cosmotown')
            ->assertDontSee('appears here after the domain is registered');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'epp_code' => 'EPP-CHIEF-DAKING',
        ]);
    }

    public function test_non_admin_cannot_update_admin_domain_nameservers(): void
    {
        $customer = User::factory()->customer()->create();
        $domain = $this->domain($customer);

        $this->actingAs($customer)
            ->put(route('admin.domains.nameservers', $domain), [
                'nameserver_1' => 'ns1.new.com',
                'nameserver_2' => 'ns2.new.com',
            ])
            ->assertForbidden();
    }

    private function domain(?User $owner = null): Domain
    {
        return Domain::create([
            'user_id' => ($owner ?? User::factory()->create())->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'nameserver_1' => 'ns1.old.com',
            'nameserver_2' => 'ns2.old.com',
        ]);
    }

    private function attachCosmotown(string $extension, bool $default = true, bool $bindExtension = true): Registrar
    {
        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-admin-show-'.uniqid(),
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
