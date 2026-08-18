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

class AdminCosmotownImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_imports_unmatched_cosmotown_domain_onto_customer_without_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $this->attachCosmotown();

        DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'domains' => [
                    ['domain' => 'orphan.com', 'expiration_date' => '2028-03-01', 'locked' => true],
                ],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'orphan.com',
                'expiration_date' => '2028-03-01',
                'nameservers' => ['ns1.talksasa.com', 'ns2.talksasa.com'],
                'locked' => true,
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.domains.cosmotown-unmatched'))
            ->assertOk()
            ->assertSee('orphan.com');

        $this->actingAs($admin)
            ->from(route('admin.domains.cosmotown-unmatched'))
            ->post(route('admin.domains.cosmotown-import'), [
                'fqdn' => 'orphan.com',
                'user_id' => $customer->id,
            ])
            ->assertRedirect(route('admin.domains.cosmotown-unmatched'))
            ->assertSessionHasErrors('confirm_no_invoice');

        $this->assertDatabaseMissing('domains', ['name' => 'orphan']);

        $this->actingAs($admin)
            ->post(route('admin.domains.cosmotown-import'), [
                'fqdn' => 'orphan.com',
                'user_id' => $customer->id,
                'confirm_no_invoice' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('domains', [
            'name' => 'orphan',
            'extension' => '.com',
            'user_id' => $customer->id,
            'registrar_handle' => 'orphan.com',
            'auto_renew' => false,
        ]);

        $domain = Domain::query()->where('name', 'orphan')->first();
        $this->assertSame('2028-03-01', $domain->expires_at?->toDateString());
        $this->assertSame(0, $domain->renewalOrders()->count());
    }

    public function test_import_rejects_name_already_on_an_account(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $this->attachCosmotown();
        DomainExtension::query()->firstOrCreate(
            ['extension' => '.com'],
            ['description' => 'COM', 'enabled' => true]
        );

        Domain::create([
            'user_id' => $customer->id,
            'name' => 'orphan',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'orphan.com',
                'expiration_date' => '2028-03-01',
            ], 200),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.domains.cosmotown-unmatched'))
            ->post(route('admin.domains.cosmotown-import'), [
                'fqdn' => 'orphan.com',
                'user_id' => $customer->id,
                'confirm_no_invoice' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    private function attachCosmotown(): Registrar
    {
        return Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-import-'.uniqid(),
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => true,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);
    }
}
