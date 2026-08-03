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

        $this->assertSame(CosmotownClient::SANDBOX_BASE, CosmotownClient::forRegistrar($sandbox)->baseUrl());
        $this->assertSame(CosmotownClient::PRODUCTION_BASE, CosmotownClient::forRegistrar($production)->baseUrl());
        $this->assertSame('https://custom.example/v1/', CosmotownClient::forRegistrar($override)->baseUrl());
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

    public function test_client_maps_403_to_cosmotown_exception(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'error_message' => 'API key is invalid or IP is not whitelisted.',
            ], 403),
        ]);

        $this->expectException(CosmotownException::class);
        $this->expectExceptionMessage('API key is invalid or IP is not whitelisted.');

        CosmotownClient::forRegistrar($this->makeRegistrar())->getDomainAuthCode('example.com');
    }

    public function test_test_connection_succeeds_on_authenticated_400(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'error_message' => 'Domain not found',
            ], 400),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->testConnection($this->makeRegistrar());

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Connected to Cosmotown', $result['message']);
    }

    public function test_test_connection_fails_on_403(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/domainepp*' => Http::response([
                'error_message' => 'Unauthorized',
            ], 403),
        ]);

        $driver = new CosmotownRegistrarDriver;
        $result = $driver->testConnection($this->makeRegistrar());

        $this->assertFalse($result['success']);
        $this->assertSame('Unauthorized', $result['message']);
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

    public function test_register_domain_is_unsupported(): void
    {
        $domain = new Domain([
            'name' => 'example',
            'extension' => '.com',
        ]);

        $result = (new CosmotownRegistrarDriver)->registerDomain(
            $this->makeRegistrar(),
            $domain,
            1,
            [['name' => 'ns1.example.com']]
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not document this operation', $result['message']);
        $this->assertFalse((new CosmotownRegistrarDriver)->supportsRegistration());
    }

    public function test_registrar_manager_resolves_cosmotown_driver(): void
    {
        $registrar = $this->makeRegistrar();
        $driver = app(RegistrarManager::class)->driver($registrar);

        $this->assertInstanceOf(CosmotownRegistrarDriver::class, $driver);
    }
}
