<?php

namespace Tests\Feature\Admin;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\Registrar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminCosmotownInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_cosmotown_inventory_from_domains_index(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
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
        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'shop',
            'extension' => '.com',
            'status' => 'active',
            'expires_at' => '2025-01-01',
        ]);

        Http::fake([
            'cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'domains' => [
                    ['domain' => 'shop.com', 'expiration_date' => '2027-12-01'],
                ],
            ], 200),
            'cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'shop.com',
                'expiration_date' => '2027-12-01',
                'nameservers' => ['ns1.talksasa.com', 'ns2.talksasa.com'],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.domains.index'))
            ->assertOk()
            ->assertSee('Sync from Cosmotown');

        $this->actingAs($admin)
            ->post(route('admin.domains.sync-cosmotown'))
            ->assertRedirect(route('admin.domains.index'))
            ->assertSessionHas('success');

        $domain->refresh();
        $this->assertSame('2027-12-01', $domain->expires_at?->toDateString());
        $this->assertSame('ns1.talksasa.com', $domain->nameserver_1);
    }

    public function test_non_admin_cannot_sync_cosmotown_inventory(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.domains.sync-cosmotown'))
            ->assertForbidden();
    }
}
