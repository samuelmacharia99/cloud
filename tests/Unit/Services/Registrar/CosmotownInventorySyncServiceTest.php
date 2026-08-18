<?php

namespace Tests\Unit\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\Registrar;
use App\Models\User;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\CosmotownInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CosmotownInventorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRegistrar(): Registrar
    {
        $registrar = Registrar::query()->updateOrCreate(
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

        return $registrar;
    }

    public function test_list_all_domains_paginates_until_short_page(): void
    {
        Http::fake(function ($request) {
            $offset = (int) $request['offset'];

            if ($offset === 0) {
                return Http::response([
                    'domains' => [
                        ['domain' => 'one.com'],
                        ['domain' => 'two.com'],
                    ],
                ], 200);
            }

            return Http::response([
                'domains' => [
                    ['domain' => 'three.com'],
                ],
            ], 200);
        });

        $listed = CosmotownClient::forRegistrar($this->makeRegistrar())->listAllDomains(2);

        $this->assertCount(3, $listed);
        $this->assertSame('three.com', $listed[2]['domain']);
    }

    public function test_sync_updates_matching_domain_expiry_and_nameservers(): void
    {
        $registrar = $this->makeRegistrar();
        $owner = User::factory()->create();
        $domain = Domain::create([
            'user_id' => $owner->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'expired',
            'expires_at' => '2024-01-01',
            'nameserver_1' => 'ns1.old.test',
            'nameserver_2' => 'ns2.old.test',
            'registrar' => 'manual',
        ]);

        Http::fake([
            'cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'domains' => [
                    ['domain' => 'example.com', 'expiration_date' => '2027-08-18'],
                    ['domain' => 'only-at-cosmotown.com', 'expiration_date' => '2027-01-01'],
                ],
            ], 200),
            'cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'example.com',
                'expiration_date' => '2027-08-18',
                'nameservers' => ['ns1.talksasa.com', 'ns2.talksasa.com'],
            ], 200),
        ]);

        $result = app(CosmotownInventorySyncService::class)->sync($registrar);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['unmatched_count']);
        $this->assertContains('only-at-cosmotown.com', $result['unmatched']);
        $this->assertSame(0, Domain::query()->where('name', 'only-at-cosmotown')->count());

        $domain->refresh();
        $this->assertSame('2027-08-18', $domain->expires_at?->toDateString());
        $this->assertSame('ns1.talksasa.com', $domain->nameserver_1);
        $this->assertSame('ns2.talksasa.com', $domain->nameserver_2);
        $this->assertSame('cosmotown', $domain->registrar);
        $this->assertSame('example.com', $domain->registrar_handle);
        $this->assertSame('active', $domain->status);
        $this->assertSame($owner->id, $domain->user_id);
    }

    public function test_sync_does_not_unsuspend_a_suspended_domain(): void
    {
        $registrar = $this->makeRegistrar();
        $domain = Domain::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'held',
            'extension' => '.com',
            'status' => 'suspended',
            'expires_at' => '2024-01-01',
        ]);

        Http::fake([
            'cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'domains' => [
                    ['domain' => 'held.com', 'expiration_date' => '2028-01-01'],
                ],
            ], 200),
            'cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'held.com',
                'expiration_date' => '2028-01-01',
                'nameservers' => ['ns1.talksasa.com', 'ns2.talksasa.com'],
            ], 200),
        ]);

        app(CosmotownInventorySyncService::class)->sync($registrar);

        $domain->refresh();
        $this->assertSame('suspended', $domain->status);
        $this->assertSame('2028-01-01', $domain->expires_at?->toDateString());
        $this->assertSame('ns1.talksasa.com', $domain->nameserver_1);
    }
}
