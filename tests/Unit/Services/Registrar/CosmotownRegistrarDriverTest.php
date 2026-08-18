<?php

namespace Tests\Unit\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\Registrar;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\Drivers\CosmotownRegistrarDriver;
use App\Services\Registrar\RegistrarManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CosmotownRegistrarDriverTest extends TestCase
{
    use RefreshDatabase;

    private function makeRegistrar(array $config = [], string $environment = 'sandbox'): Registrar
    {
        return Registrar::query()->create([
            'name' => 'Cosmotown Test',
            'slug' => 'cosmotown-test-'.uniqid(),
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => $environment,
            'is_active' => true,
            'is_default' => false,
            'config' => array_merge([
                'api_token' => 'test-token',
                'contact_first_name' => 'Jane',
                'contact_last_name' => 'Doe',
                'contact_email' => 'jane@example.com',
                'contact_phone' => '+254700000000',
                'contact_address1' => '1 Main St',
                'contact_city' => 'Nairobi',
                'contact_state' => 'Nairobi',
                'contact_zip' => '00100',
                'contact_country' => 'KE',
            ], $config),
            'sort_order' => 0,
        ]);
    }

    public function test_client_uses_sandbox_and_production_base_urls(): void
    {
        $sandbox = $this->makeRegistrar([], 'sandbox');
        $production = $this->makeRegistrar([], 'production');
        $override = $this->makeRegistrar(['api_base_url' => 'https://custom.example/v1']);
        $apex = $this->makeRegistrar(['api_base_url' => 'https://cosmotown.com/v1/'], 'production');
        $homepage = $this->makeRegistrar(['api_base_url' => 'https://www.cosmotown.com/'], 'production');

        $this->assertSame(CosmotownClient::SANDBOX_BASE, CosmotownClient::forRegistrar($sandbox)->baseUrl());
        $this->assertSame('https://www.cosmotown.com/v1/', CosmotownClient::PRODUCTION_BASE);
        $this->assertSame(CosmotownClient::PRODUCTION_BASE, CosmotownClient::forRegistrar($production)->baseUrl());
        $this->assertSame('https://custom.example/v1/', CosmotownClient::forRegistrar($override)->baseUrl());
        $this->assertSame('https://www.cosmotown.com/v1/', CosmotownClient::forRegistrar($apex)->baseUrl());
        $this->assertSame('https://www.cosmotown.com/v1/', CosmotownClient::forRegistrar($homepage)->baseUrl());
    }

    public function test_client_sends_api_token_header_and_returns_auth_code(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response(['auth_code' => 'EPP-SECRET'], 200),
        ]);

        $registrar = $this->makeRegistrar();
        $result = CosmotownClient::forRegistrar($registrar)->getDomainAuthCode('example.com');

        $this->assertSame('EPP-SECRET', $result['auth_code']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-TOKEN', 'test-token')
                && $request->hasHeader('Accept', 'application/json')
                && str_contains($request->url(), 'reseller/domainepp')
                && $request['domain'] === 'example.com';
        });
    }

    public function test_client_maps_403_to_cosmotown_exception_with_outbound_ip(): void
    {
        Cache::flush();
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'error_message' => 'Your IP is not whitelisted',
            ], 403),
            'api.ipify.org*' => Http::response(['ip' => '203.0.113.10']),
            'api64.ipify.org*' => Http::response(['ip' => '203.0.113.10']),
        ]);

        $this->expectException(CosmotownException::class);
        $this->expectExceptionMessage('Your IP is not whitelisted. Whitelist this app server\'s public IP in Cosmotown Reseller API settings: 203.0.113.10.');

        CosmotownClient::forRegistrar($this->makeRegistrar())->getDomainAuthCode('example.com');
    }

    public function test_test_connection_lists_domains_without_ping(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'domains' => [
                    ['domain' => 'one.example', 'expiration_date' => '2027-01-01'],
                    ['domain' => 'two.example', 'expiration_date' => '2027-06-01'],
                ],
            ], 200),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->testConnection($this->makeRegistrar());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['domain_sample_count']);
        $this->assertStringContainsString('sandbox', $result['message']);
        $this->assertStringContainsString('sandbox.cosmotown.com', $result['message']);
        $this->assertStringContainsString('2 domain(s)', $result['message']);
    }

    public function test_test_connection_uses_www_production_host(): void
    {
        Http::fake([
            'www.cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'domains' => [
                    ['domain' => 'live.example'],
                ],
            ], 200),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->testConnection($this->makeRegistrar([], 'production'));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('www.cosmotown.com', $result['message']);
        $this->assertSame(1, $result['domain_sample_count']);
    }

    public function test_test_connection_falls_back_to_domainepp_when_list_is_unavailable(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'error_message' => 'Domain API under maintenance',
            ], 503),
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'auth_code' => '',
            ], 200),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->testConnection($this->makeRegistrar());

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Token accepted', $result['message']);
        $this->assertNull($result['domain_sample_count']);
    }

    public function test_client_rejects_html_success_as_wrong_host(): void
    {
        Http::fake([
            'www.cosmotown.com/v1/reseller/listdomains*' => Http::response(
                '<!DOCTYPE html><html><body>Home</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $this->expectException(CosmotownException::class);
        $this->expectExceptionMessage('web page instead of JSON');

        CosmotownClient::forRegistrar($this->makeRegistrar([], 'production'))->listDomains(5, 0);
    }

    public function test_test_connection_fails_on_403_and_names_the_outbound_ip(): void
    {
        Cache::flush();
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/listdomains*' => Http::response([
                'error_message' => 'Your IP is not whitelisted',
            ], 403),
            'api.ipify.org*' => Http::response(['ip' => '198.51.100.20']),
            'api64.ipify.org*' => Http::response(['ip' => '198.51.100.20']),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->testConnection($this->makeRegistrar());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Your IP is not whitelisted', $result['message']);
        $this->assertStringContainsString('198.51.100.20', $result['message']);
    }

    public function test_get_domain_auth_code_via_driver(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response(['auth_code' => 'ABC123'], 200),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->getDomainAuthCode($this->makeRegistrar(), 'foo.bar');

        $this->assertTrue($result['success']);
        $this->assertSame('ABC123', $result['auth_code']);
    }

    public function test_live_nameservers_reads_domaininfo(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'nameservers' => ['ns1.live.example', 'ns2.live.example'],
            ], 200),
        ]);

        $domain = new Domain([
            'name' => 'shop',
            'extension' => '.com',
        ]);

        $hosts = (new CosmotownRegistrarDriver)->liveNameservers($this->makeRegistrar(), $domain);

        $this->assertSame(['ns1.live.example', 'ns2.live.example'], $hosts);
    }

    public function test_extract_auth_code_reads_nested_payloads(): void
    {
        $this->assertSame('NESTED-EPP', CosmotownClient::extractAuthCode([
            'data' => ['auth_code' => 'NESTED-EPP'],
        ]));
    }

    public function test_sync_default_contacts_posts_contactinfo(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/contactinfo' => Http::response([
                'domain' => 'n/a',
                'locked' => false,
            ], 200),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->syncDefaultContacts($this->makeRegistrar());

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'reseller/contactinfo')
                && ($body['registrant']['FirstName'] ?? null) === 'Jane'
                && ($body['billing']['Email'] ?? null) === 'jane@example.com';
        });
    }

    public function test_register_domain_submits_items_and_nameservers(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/contactinfo*' => Http::response(['status' => 'processed'], 200),
            'sandbox.cosmotown.com/v1/reseller/registerdomains' => Http::response([
                ['domain' => 'example.com', 'status' => 'processed'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'example.com',
                'expiration_date' => '2027-08-18',
            ], 200),
        ]);

        $domain = new Domain([
            'name' => 'example',
            'extension' => '.com',
        ]);

        $result = (new CosmotownRegistrarDriver)->registerDomain(
            $this->makeRegistrar(),
            $domain,
            1,
            [['name' => 'ns1.talksasa.com'], ['name' => 'ns2.talksasa.com']]
        );

        $this->assertTrue($result['success']);
        $this->assertSame('REQ', $result['status']);
        $this->assertSame('example.com', $result['external_id']);
        $this->assertTrue((new CosmotownRegistrarDriver)->supportsRegistration());

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'reseller/registerdomains')
                && ($body['items'][0]['name'] ?? null) === 'example.com'
                && (int) ($body['items'][0]['years'] ?? 0) === 1;
        });
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'reseller/savedomainnameservers')
                && ($body['domain'] ?? null) === 'example.com'
                && ($body['nameservers'] ?? []) === ['ns1.talksasa.com', 'ns2.talksasa.com'];
        });
    }

    public function test_transfer_domain_base64_encodes_auth_code(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/contactinfo*' => Http::response(['status' => 'processed'], 200),
            'sandbox.cosmotown.com/v1/reseller/transferdomains' => Http::response([
                ['domain' => 'move.com', 'status' => 'processed'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/savedomainnameservers' => Http::response(['status' => 'processed'], 200),
        ]);

        $domain = new Domain([
            'name' => 'move',
            'extension' => '.com',
        ]);

        $result = (new CosmotownRegistrarDriver)->transferDomain(
            $this->makeRegistrar(),
            $domain,
            'SecretEpp!',
            [['name' => 'ns1.talksasa.com'], ['name' => 'ns2.talksasa.com']]
        );

        $this->assertTrue($result['success']);
        $this->assertSame('REQ', $result['status']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'reseller/transferdomains')
                && ($body['items'][0]['name'] ?? null) === 'move.com'
                && ($body['items'][0]['authCode'] ?? null) === base64_encode('SecretEpp!');
        });
    }

    public function test_renew_domain_treats_processed_as_success(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/renewdomains' => Http::response(['status' => 'processed'], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'example.com',
                'expiration_date' => '2028-01-01',
            ], 200),
        ]);

        $domain = new Domain([
            'name' => 'example',
            'extension' => '.com',
        ]);

        $result = (new CosmotownRegistrarDriver)->renewDomain($this->makeRegistrar(), $domain, 1);

        $this->assertTrue($result['success']);
        $this->assertSame('ACT', $result['status']);
        $this->assertSame('2028-01-01', $result['expiration_date']);
    }

    public function test_sync_domain_status_maps_active_registration(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainstatus' => Http::response([
                ['domain' => 'example.com', 'registration_status' => 'active'],
            ], 200),
            'sandbox.cosmotown.com/v1/reseller/domaininfo*' => Http::response([
                'domain' => 'example.com',
                'expiration_date' => '2027-08-18',
            ], 200),
        ]);

        $domain = new Domain([
            'name' => 'example',
            'extension' => '.com',
        ]);

        $result = (new CosmotownRegistrarDriver)->syncDomainStatus($this->makeRegistrar(), $domain);

        $this->assertTrue($result['success']);
        $this->assertSame('ACT', $result['status']);
        $this->assertSame('example.com', $result['external_id']);
        $this->assertSame('2027-08-18', $result['expiration_date']);
    }

    public function test_registrar_manager_resolves_cosmotown_driver(): void
    {
        $registrar = $this->makeRegistrar();
        $driver = app(RegistrarManager::class)->driver($registrar);

        $this->assertInstanceOf(CosmotownRegistrarDriver::class, $driver);
    }
}
