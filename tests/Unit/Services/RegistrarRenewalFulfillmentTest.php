<?php

namespace Tests\Unit\Services;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainRenewalOrder;
use App\Models\Registrar;
use App\Models\User;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrarRenewalFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfill_renewal_does_not_complete_when_tld_has_no_api_registrar(): void
    {
        $reseller = User::factory()->reseller()->create();

        DomainExtension::create([
            'extension' => '.ke',
            'description' => 'KE',
            'enabled' => true,
        ]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'manualtld',
            'extension' => '.ke',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addMonths(3),
        ]);

        $renewal = DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $reseller->id,
            'years' => 1,
            'amount' => 2000,
            'status' => 'pushed',
            'pushed_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $result = app(RegistrarFulfillmentService::class)->fulfillRenewal($renewal);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['completed']);
        $this->assertStringContainsString('not linked to an API registrar', $result['message']);
        $this->assertSame('pushed', $renewal->fresh()->status);
    }

    public function test_fulfill_renewal_uses_cosmotown_when_domain_has_no_openprovider_id(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/renewdomains' => Http::response(['status' => 'processed'], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'resellerdom.com',
                'expiration_date' => '2028-09-01',
            ], 200),
        ]);

        Registrar::query()->create([
            'name' => 'Cosmotown Live',
            'slug' => 'cosmotown-live-test',
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => false,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);

        $reseller = User::factory()->reseller()->create();

        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
            'registrar_id' => Registrar::query()->where('slug', 'openprovider')->value('id'),
        ]);

        $domain = Domain::create([
            'user_id' => $reseller->id,
            'name' => 'resellerdom',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
            'expires_at' => now()->addMonths(2),
        ]);

        $renewal = DomainRenewalOrder::create([
            'domain_id' => $domain->id,
            'user_id' => $reseller->id,
            'years' => 1,
            'amount' => 1400,
            'status' => 'pushed',
            'pushed_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $result = app(RegistrarFulfillmentService::class)->fulfillRenewal($renewal);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertTrue($result['completed']);
        $this->assertSame('completed', $renewal->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'reseller/renewdomains'));
    }
}
